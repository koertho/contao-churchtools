<?php
require __DIR__.'/event-runtime.php';
require dirname(__DIR__).'/tests/Sync/RemoteFixture.php';
use Contao\CoreBundle\Event\InvalidateCacheTagsEvent;
use Koertho\ChurchToolsBundle\Tests\Sync\RemoteFixture;
use Koertho\ChurchToolsBundle\Model\ChurchToolsEntryModel;

$count=0;$check=static function(bool $ok,string $message)use(&$count):void{if(!$ok)throw new RuntimeException($message);++$count;};
$prefix='ct_event_'.bin2hex(random_bytes(5));$aid=$cid=$uid=null;$listener=null;$trigger=null;$processes=[];
$observer=\Doctrine\DBAL\DriverManager::getConnection($db->getParams());
$dispatcher=$c->get('event_dispatcher');
try {
 $db->insert('tl_user',['username'=>$prefix,'admin'=>1]);$uid=(int)$db->lastInsertId();
 $user=\Contao\BackendUser::loadUserById($uid);
 $c->get('security.token_storage')->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user,'contao_backend',$user->getRoles()));
 $db->insert('tl_calendar',['title'=>$prefix]);$cid=(int)$db->lastInsertId();
 $db->insert('tl_church_tools_archive',['name'=>$prefix,'calendarIds'=>'[110]']);$aid=(int)$db->lastInsertId();
 $entry=ChurchToolsEntryModel::saveSource($aid,RemoteFixture::row($prefix));$eid=(int)$entry->id;
 $source=$db->fetchAssociative('SELECT * FROM tl_church_tools_entry WHERE id=?',[$eid]);
 $lock=substr('churchtools:'.hash('sha256',$db->getDatabase().':'.$aid),0,64);
 $observer->fetchOne('SELECT GET_LOCK(?,0)',[$lock]);
 try{$actions->change($eid,'create',$cid);$check(false,'Busy lock must conflict');}catch(\Symfony\Component\HttpKernel\Exception\ConflictHttpException){$check(true,'Shared sync lock honored');}
 $check((int)$db->fetchOne('SELECT COUNT(*) FROM tl_calendar_events WHERE pid=?',[$cid])===0,'Busy lock no side effects');
 $observer->fetchOne('SELECT RELEASE_LOCK(?)',[$lock]);
 foreach(['dispatch','write','postcommit']as$failure){
  $phases=[];
  $listener=static function(InvalidateCacheTagsEvent $event)use($db,$aid,$failure,&$phases):void{
   if(!in_array('church_tools.archive.'.$aid,$event->getTags(),true))return;
   $level=$db->getTransactionNestingLevel();$phases[]=$level;
   if(($failure==='dispatch'&&$level===1)||($failure==='postcommit'&&$level===0))throw new RuntimeException('Synthetic cache failure');
  };
  $dispatcher->addListener(InvalidateCacheTagsEvent::class,$listener,100);
  if($failure==='write'){
   $trigger=$prefix.'_link';
   $db->executeStatement('CREATE TRIGGER '.$trigger.' BEFORE UPDATE ON tl_church_tools_entry FOR EACH ROW BEGIN IF NEW.id='.$eid.' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'Synthetic link failure\'; END IF; END');
  }
  try{$actions->change($eid,'create',$cid);$check(false,'Injected failure must escape');}catch(Throwable $e){$check(!str_contains($e->getMessage(),'must escape'),'Failure observed');}
  $dispatcher->removeListener(InvalidateCacheTagsEvent::class,$listener);$listener=null;
  if($trigger){$db->executeStatement('DROP TRIGGER '.$trigger);$trigger=null;}
  $n=(int)$observer->fetchOne('SELECT COUNT(*) FROM tl_calendar_events WHERE pid=?',[$cid]);
  $linked=$observer->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?',[$eid]);
  if($failure!=='postcommit'){
   $check($n===0&&$linked===null,'Event/link rollback from independent connection');
   $check((int)$observer->fetchOne('SELECT COUNT(*) FROM tl_content WHERE ptable=?',['tl_calendar_events'])===0,'No orphan details after rollback');
   $check($observer->fetchAssociative('SELECT * FROM tl_church_tools_entry WHERE id=?',[$eid])===$source,'Source unchanged after rollback');
  }else{
   $check($n===1&&$linked!==null&&$phases===[1,0],'Postcommit failure leaves durable link with both invalidations');
   $check($actions->change($eid,'create',$cid)===(int)$linked,'Retry after postcommit failure idempotent');
   $actions->change($eid,'unlink');
   $db->delete('tl_content',['pid'=>$linked,'ptable'=>'tl_calendar_events']);$db->delete('tl_calendar_events',['id'=>$linked]);
  }
  $check((int)$observer->fetchOne('SELECT IS_FREE_LOCK(?)',[$lock])===1,'Lock always released');
 }
 // Two independent PHP processes start together; at most one may create.
 foreach([1,2]as$i){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/event-worker.php',$project,(string)$eid,(string)$cid,(string)$uid],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,$project,getenv());$processes[]=[$p,$pipes[1]];}
 $out=[];foreach($processes as[$p,$pipe]){$out[]=trim(stream_get_contents($pipe));fclose($pipe);$check(proc_close($p)===0,'Concurrent worker completed');}$processes=[];
 $check(count(array_filter($out,static fn($status)=>$status==='locked'))===1 && count(array_filter($out,static fn($status)=>$status==='created-or-existing'))===1,'Concurrent overlap: one creator and one lock conflict');
 $linked=$actions->change($eid,'create',$cid);
 $check((int)$observer->fetchOne('SELECT COUNT(*) FROM tl_calendar_events WHERE pid=?',[$cid])===1,'Concurrent/retried create produces one event');
 $check((int)$observer->fetchOne('SELECT COUNT(*) FROM tl_content WHERE pid=? AND ptable=?',[$linked,'tl_calendar_events'])===1,'Concurrent create produces one detail element');
 $before=$observer->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?',[$linked]);
 $remote=new RemoteFixture();$remote->rows=[RemoteFixture::row($prefix),RemoteFixture::row($prefix.'other')];
 $sync=new \Koertho\ChurchToolsBundle\Sync\Synchronizer(new \Koertho\ChurchToolsBundle\Sync\SnapshotFetcher($remote->client()),$db,$c->get('contao.framework'),$c->get('contao.cache.tag_manager'));
 $check($sync->synchronize($aid,new DateTimeImmutable('2026-09-17T12:00:00Z'))[$aid]['status']==='success','Sync follows link');
 $check((int)$observer->fetchOne('SELECT contaoEventId FROM tl_church_tools_entry WHERE id=?',[$eid])===$linked,'Sync retains link');
 $remote->rows=[RemoteFixture::row($prefix.'other')];
 $check($sync->synchronize($aid,new DateTimeImmutable('2026-09-17T12:00:00Z'))[$aid]['status']==='success','Nonempty source removal');
 $check(!$observer->fetchOne('SELECT id FROM tl_church_tools_entry WHERE id=?',[$eid]),'Source row removed');
 $check($observer->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?',[$linked])===$before,'Sync/source removal preserves core event exactly');
}catch(Throwable $e){fwrite(STDERR,'Event transaction FAIL after '.$count.' checks: '.$e::class.' '.$e->getMessage()."\n");$failed=true;}
finally{
 foreach($processes as[$p,$pipe]){proc_terminate($p);fclose($pipe);proc_close($p);}
 if($listener)$dispatcher->removeListener(InvalidateCacheTagsEvent::class,$listener);
 if($trigger)$db->executeStatement('DROP TRIGGER '.$trigger);
 if($aid){$db->delete('tl_church_tools_entry',['pid'=>$aid]);$db->delete('tl_church_tools_archive',['id'=>$aid]);}
 if($cid){foreach($db->fetchFirstColumn('SELECT id FROM tl_calendar_events WHERE pid=?',[$cid])as$id)$db->delete('tl_content',['pid'=>$id,'ptable'=>'tl_calendar_events']);$db->delete('tl_calendar_events',['pid'=>$cid]);$db->delete('tl_calendar',['id'=>$cid]);}
 if($uid)$db->delete('tl_user',['id'=>$uid]);
 $observer->close();$kernel->shutdown();
}
if($failed??false)exit(1);echo 'Event transaction PASS: '.$count." checks; fixtures removed.\n";
