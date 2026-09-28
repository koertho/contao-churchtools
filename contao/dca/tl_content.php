<?php

declare(strict_types=1);

foreach (['church_tools_list', 'church_tools_calendar'] as $type) {
    $GLOBALS['TL_DCA']['tl_content']['palettes'][$type] = '{type_legend},type;{config_legend},churchToolsArchives'.($type === 'church_tools_list' ? ',churchToolsDays' : '').';{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID;{invisible_legend:hide},invisible,start,stop';
}

$GLOBALS['TL_DCA']['tl_content']['fields']['churchToolsArchives'] = [
    'exclude' => true,
    'inputType' => 'checkbox',
    'foreignKey' => 'tl_church_tools_archive.name',
    'eval' => ['multiple' => true, 'mandatory' => true],
    'sql' => ['type' => 'blob', 'notnull' => false],
];
$GLOBALS['TL_DCA']['tl_content']['fields']['churchToolsDays'] = [
    'exclude' => true,
    'inputType' => 'text',
    'eval' => ['rgxp' => 'digit', 'minval' => 1, 'maxval' => 366, 'tl_class' => 'w50'],
    'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 7],
];
