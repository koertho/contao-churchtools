<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Sync;

use Koertho\ChurchToolsBundle\Api\ApiException;
use Koertho\ChurchToolsBundle\Api\ChurchToolsClient;
use Koertho\ChurchToolsBundle\Storage\SourceOccurrence;

/** Empirical completeness checks, never a claim that ChurchTools guarantees a total. */
final class SnapshotFetcher
{
    public function __construct(private readonly ChurchToolsClient $client)
    {
    }

    /** @return array<string, array{fields: array, internal: bool}> */
    public function fetch(array $calendarIds, SyncWindow $window): array
    {
        if ($calendarIds === []) {
            return [];
        }
        $discovered = array_column($this->client->calendars(), 'id');
        if (count(array_unique($discovered)) !== count($discovered) || array_diff($calendarIds, $discovered)) {
            throw new ApiException('Selected calendar missing or ambiguous in discovery; archive unchanged.');
        }
        $snapshot = [];
        foreach ($calendarIds as $id) {
            $full = $this->map($this->client->appointments([$id], $window->from, $window->to));
            if ($full === []) {
                throw new ApiException('Empty calendar window cannot establish appointment access; archive unchanged.');
            }
            $chunks = [];
            for ($from = $window->from; $from < $window->to; $from = $to) {
                $to = min($from->modify('+31 days'), $window->to);
                // Compare the full returned union, including observed upper-bound overlaps.
                $this->merge($chunks, $this->map($this->client->appointments([$id], $from, $to)));
            }
            ksort($full);
            ksort($chunks);
            if ($full !== $chunks) {
                throw new ApiException('Full and split calendar windows disagree; archive unchanged.');
            }
            $this->merge($snapshot, $full);
        }
        // Discovery changes during retrieval invalidate the entire archive snapshot.
        $after = array_column($this->client->calendars(), 'id');
        if (count(array_unique($after)) !== count($after) || array_diff($calendarIds, $after)) {
            throw new ApiException('Calendar access changed during retrieval; archive unchanged.');
        }

        return $snapshot;
    }

    private function map(array $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $internal = $row['appointment']['base']['isInternal'];
            // Validate the identical storage contract even for transient internal identities.
            $row['appointment']['base']['isInternal'] = false;
            $fields = SourceOccurrence::fields($row);
            // Keep out-of-window returned UIDs too: a visible move is configuration cleanup,
            // never a source-deletion count. Writes still apply the local overlap predicate.
            $key = 'uid:'.$fields['occurrenceUid'];
            $value = ['fields' => $fields, 'internal' => $internal];
            if (isset($mapped[$key]) && $mapped[$key] !== $value) {
                throw new ApiException('Conflicting mapped occurrence; archive unchanged.');
            }
            $mapped[$key] = $value;
        }

        $checked = [];
        $this->merge($checked, $mapped);

        return $checked;
    }

    private function merge(array &$target, array $source): void
    {
        foreach ($source as $key => $value) {
            if (isset($target[$key]) && $target[$key] !== $value) {
                throw new ApiException('Conflicting occurrence across calendar windows; archive unchanged.');
            }
            $target[$key] = $value;
        }
        if (count($target) > 10000 || strlen(json_encode($target, JSON_THROW_ON_ERROR)) > 16777216) {
            throw new ApiException('Snapshot exceeds supported storage budget; archive unchanged.');
        }
    }
}
