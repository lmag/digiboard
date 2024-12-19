<?php
/* Copyright (C) 2024 EVARISK <technique@evarisk.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    class/digiboarddashboard.class.php
 * \ingroup digiboard
 * \brief   Class file for manage DigiBoardDashboard
 */

/**
 * Class for DigiBoardDashboard
 */
class DigiboardDashboard
{
    /**
     * @var DoliDB Database handler
     */
    public DoliDB $db;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        $this->db = $db;
    }

    /**
     * Load dashboard info
     *
     * @return array
     * @throws Exception
     */
    public function load_dashboard(): array
    {
        $array['lists'] = [];

        if (isModEnabled('digiriskdolibarr') && isModEnabled('multicompany')) {
            $getDigiRiskStatsList = $this->getDigiRiskStatsList();
            $array['digiriskdolibarr']['lists'] = [$getDigiRiskStatsList];
        }

        return $array;
    }

    /**
     * Get digirisk stats list with API
     *
     * @return array     Graph datas (label/color/type/title/data etc..)
     * @throws Exception
     */
    public function getDigiRiskStatsList(): array
    {
        global $db, $mc, $langs;

        // Graph Title parameters
        $array['title'] = $langs->transnoentities('DigiRiskStatsList');
        $array['picto'] = 'digiriskdolibarr_color@digiriskdolibarr';

        // Graph parameters
        $array['type']   = 'list';
        $array['labels'] = ['Site', 'Siret', 'RiskAssessmentDocument', 'DelayGenerateDate', 'NbEmployees', 'NbEmployeesInvolved', 'GreyRisk', 'OrangeRisk', 'RedRisk', 'BlackRisk'];
        if (getDolGlobalInt('DIGIBOARD_DIGIRISIK_STATS_LOAD_ACCIDENT') > 0) {
            $array['labels'][] = ['NbPresquAccidents', 'NbAccidents', 'NbAccidentsByEmployees', 'NbAccidentInvestigations', 'WorkStopDays', 'FrequencyIndex', 'FrequencyRate', 'GravityRate'];
        }

        require_once __DIR__ . '/../../digiriskdolibarr/class/digiriskdolibarrdocuments/riskassessmentdocument.class.php';
        require_once __DIR__ . '/../../digiriskdolibarr/class/evaluator.class.php';
        require_once __DIR__ . '/../../digiriskdolibarr/class/digiriskelement.class.php';
        require_once __DIR__ . '/../../digiriskdolibarr/class/riskanalysis/risk.class.php';
        require_once __DIR__ . '/../../digiriskdolibarr/class/accident.class.php';

        $riskAssessmentDocument = new RiskAssessmentDocument($this->db);
        $evaluator              = new Evaluator($this->db);
        $digiriskElement        = new DigiriskElement($this->db);
        $risk                   = new Risk($this->db);
        $accident               = new Accident($this->db);

        $riskAssessmentDocument->ismultientitymanaged = 0;
        $accident->ismultientitymanaged               = 0;
        $evaluator->ismultientitymanaged              = 0;

        $arrayDigiRiskStatsList = [];
        $filter                 = '';
        $riskAssessmentCotation = [1 => 'GreyRisk', 2 => 'OrangeRisk', 3 => 'RedRisk', 4 => 'BlackRisk'];
        $sharingEntities        = $mc->sharings['digiriskstats'] ?? [];
        $entities               = $mc->getEntitiesList(false, false, true);
        if (!empty($sharingEntities)) {
            $currentEntity[]           = 1;
            $sharingEntitiesAndCurrent = array_unique(array_merge($currentEntity, $sharingEntities));
            $filter                    = 'AND t.fk_element NOT IN' . $digiriskElement->getTrashExclusionSqlFilter();
        }

        if (!empty($entities)) {
            $total = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 'nbEmployees' => 0];
            foreach ($entities as $entityID => $entityName) {
                if (!empty($sharingEntitiesAndCurrent) && !in_array($entityID, $sharingEntitiesAndCurrent)) {
                    continue;
                }

                $arrayDigiRiskStatsList[$entityID]['Site']['value']   = $entityName;
                $arrayDigiRiskStatsList[$entityID]['Site']['morecss'] = 'left bold';
                $arrayDigiRiskStatsList[$entityID]['Siret']['value']  = dolibarr_get_const($db, 'MAIN_INFO_SIRET', $entityID);

                $moreParam['entity']                                                  = $entityID;
                $filterEntity                                                         = ' AND t.entity = ' . $entityID;
                $moreParam['filter']                                                  = $filterEntity;
                $arrayGetGenerationDateInfos                                          = $riskAssessmentDocument->getGenerationDateInfos($moreParam);
                $arrayDigiRiskStatsList[$entityID]['RiskAssessmentDocument']['value'] = $arrayGetGenerationDateInfos['lastgeneratedate'] . $arrayGetGenerationDateInfos['moreContent'];
                $arrayDigiRiskStatsList[$entityID]['DelayGenerateDate']['value']      = $arrayGetGenerationDateInfos['delaygeneratedate'];

                $moreParam['filter']                                               = ' AND u.entity IN (0,' . $entityID . ')';
                $employees                                                         = $evaluator->getNbEmployees($moreParam);
                $moreParam['filter']                                               = 't.entity = ' . $entityID;
                $arrayDigiRiskStatsList[$entityID]['NbEmployees']['value']         = $employees['nbemployees'];
                $arrayDigiRiskStatsList[$entityID]['NbEmployeesInvolved']['value'] = $evaluator->getNbEmployeesInvolved($moreParam)['nbemployeesinvolved'];
                $total['nbEmployees'] += $employees['nbemployees'];

                $moreParam['filter']                = $filter . $filterEntity;
                $moreParam['multiEntityManagement'] = false;
                $getRisksByCotation = $risk->getRisksByCotation($moreParam)['data'];
                for ($i = 1; $i <= 4; $i++) {
                    if ($i == 4) {
                        $percent                                                                  = $getRisksByCotation[$i] > 0 ? round($getRisksByCotation[$i] * 100 / array_sum($getRisksByCotation), 1) : 0;
                        $arrayDigiRiskStatsList[$entityID][$riskAssessmentCotation[$i]]['value']  = "
                            <div class='flex justify-center items-center' style='gap: 0.2em;'>
                                <span class='width50p right'>$getRisksByCotation[$i]</span>
                                <span class='width50p left' style='font-size: 0.75em;'>($percent%)</span>
                            </div>
                        ";
                    } else {
                        $arrayDigiRiskStatsList[$entityID][$riskAssessmentCotation[$i]]['value'] = $getRisksByCotation[$i];
                    }
                    $arrayDigiRiskStatsList[$entityID][$riskAssessmentCotation[$i]]['morecss']  = 'risk-evaluation-cotation';
                    $arrayDigiRiskStatsList[$entityID][$riskAssessmentCotation[$i]]['moreAttr'] = 'data-scale=' . $i . ' style="line-height: 0; border-radius: 0;"';
                    $total[$i]                                                                 += $getRisksByCotation[$i];
                }

                if (getDolGlobalInt('DIGIBOARD_DIGIRISIK_STATS_LOAD_ACCIDENT') > 0) {
                    $moreParam['filter']    = $filterEntity;
                    $join                   = ' LEFT JOIN ' . MAIN_DB_PREFIX . $accident->table_element . ' as a ON a.rowid = t.fk_accident';
                    $accidentsWithWorkStops = saturne_fetch_all_object_type('AccidentWorkStop', 'DESC', 't.rowid', 0, 0, ['customsql' => 't.entity = ' . $entityID], 'AND', false, false, false, $join);
                    $accidents              = $accident->fetchAll('', '', 0, 0, ['customsql' => ' t.status > ' . Accident::STATUS_DRAFT . $moreParam['filter']]);
                    if (empty($accidents) && !is_array($accidents)) {
                        $accidents = [];
                    }
                    if (empty($accidentsWithWorkStops) && !is_array($accidentsWithWorkStops)) {
                        $accidentsWithWorkStops = [];
                    }

                    $arrayDigiRiskStatsList[$entityID]['NbPresquAccidents']['value']        = $accident->getNbPresquAccidents()['nbpresquaccidents'];
                    $arrayDigiRiskStatsList[$entityID]['NbAccidents']['value']              = $accident->getNbAccidents($accidents, $accidentsWithWorkStops)['data']['accidents'];
                    $arrayDigiRiskStatsList[$entityID]['NbAccidentsByEmployees']['value']   = $accident->getNbAccidentsByEmployees($accidents, $accidentsWithWorkStops, $employees)['nbaccidentsbyemployees'];
                    $arrayDigiRiskStatsList[$entityID]['NbAccidentInvestigations']['value'] = $accident->getNbAccidentInvestigations($moreParam)['nbaccidentinvestigations'];
                    $arrayDigiRiskStatsList[$entityID]['WorkStopDays']['value']             = $accident->getNbWorkstopDays($accidentsWithWorkStops)['nbworkstopdays'];
                    $arrayDigiRiskStatsList[$entityID]['FrequencyIndex']['value']           = $accident->getFrequencyIndex($accidentsWithWorkStops, $employees)['frequencyindex'];
                    $arrayDigiRiskStatsList[$entityID]['FrequencyRate']['value']            = $accident->getFrequencyRate($employees)['frequencyrate'];
                    $arrayDigiRiskStatsList[$entityID]['GravityRate']['value']              = $accident->getGravityRate($employees)['gravityrate'];
                }
            }

            $totalValue                         = ['Site' => ['value' => $langs->transnoentities('Total'), 'morecss' => 'bold'], 'Siret' => ['value' => ''], 'RiskAssessmentDocument' => ['value' => ''], 'DelayGenerateDate' => ['value' => ''], 'NbEmployees' => ['value' => ''], 'NbEmployeesInvolved' => ['value' => '']];
            $totalValue['NbEmployees']['value'] = $total['nbEmployees'];
            for ($i = 1; $i <= 4; $i++) {
                $totalValue[$riskAssessmentCotation[$i]]['value']    = $total[$i];
                $totalValue[$riskAssessmentCotation[$i]]['morecss']  = 'risk-evaluation-cotation';
                $totalValue[$riskAssessmentCotation[$i]]['moreAttr'] = 'data-scale=' . $i . ' style="line-height: 0; border-radius: 0;"';
            }
            if (getDolGlobalInt('DIGIBOARD_DIGIRISIK_STATS_LOAD_ACCIDENT')) {
                $totalValue = array_merge($totalValue, ['NbPresquAccidents' => ['value' => ''], 'NbAccidents' => ['value' => ''], 'NbAccidentsByEmployees' => ['value' => ''], 'NbAccidentInvestigations' => ['value' => ''], 'WorkStopDays' => ['value' => ''], 'FrequencyIndex' => ['value' => ''], 'FrequencyRate' => ['value' => ''], 'GravityRate' => ['value' => '']]);
            }
            $arrayDigiRiskStatsList[] = $totalValue;
        }
        $array['data'] = $arrayDigiRiskStatsList;

        return $array;
    }
}
