<?php
namespace SICOR\SicAddress\Domain\Repository;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2016 SICOR DEVTEAM <dev@sicor-kdl.net>, Sicor KDL GmbH
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The repository for Addresses
 */
class AddressRepository extends \TYPO3\CMS\Extbase\Persistence\Repository
{
    /**
     * categoryRepository
     *
     * @var \SICOR\SicAddress\Domain\Repository\CategoryRepository
     * @TYPO3\CMS\Extbase\Annotation\Inject
     */
    protected $categoryRepository = NULL;

    /**
     * @var array
     */
    protected $defaultOrderings = array(
        'sorting' => \TYPO3\CMS\Extbase\Persistence\QueryInterface::ORDER_ASCENDING
    );

    /**
     * @return array
     */
    public function getDefaultOrderings()
    {
        return $this->defaultOrderings;
    }

    /**
     * @param array $defaultOrderings
     */
    public function setDefaultOrderings(array $defaultOrderings)
    {
        $this->defaultOrderings = $defaultOrderings;
    }

    public function initializeObject() {
    }

    public function findByPid($pid) {
        $query = $this->createQuery();
        $query->getQuerySettings()->setStoragePageIds(array($pid));
        return $query->execute();
    }

    public function findForVianovis() {
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(FALSE);
        return $query->execute();
    }

    /**
     * @param $categories
     * @return mixed
     */
    public function findByCategories($categories) {
        //No Categories given -> return all
        if(!$categories) {
            return $this->findAll();
        }

        $query = $this->createQuery();
        $constraints = array();
        foreach($categories as $item) {
            $constraints[] = $query->contains("categories", $item);
        }
        $con = $query->logicalOr($constraints);
        $query->matching($query->logicalAnd($con));

        return $query->execute();
    }

    /**
     * @param $category
     * @param $categoryList
     *
     * @return array
     */
    private function getParents($category, &$categoryList = array()) {
        $category = $this->categoryRepository->findByUid($category);
        $categoryList[] = $category;

        if(!$category->getParent() || ($category->getParent() && $this->categoryRepository->findByParent($category)->count()) > 0) {
            $children = $this->categoryRepository->findByParent($category);

            foreach($children as $child) {
                $this->getParents($child->getUid(), $categoryList);
            }
        }

        return $categoryList;
    }

    /**
     * @param $sql
     * @return array|\TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     */
    public function runUpdate($sql) {
        $query = $this->createQuery();
        $query->statement($sql);
        return $query->execute();
    }

    /**
     * @return array
     */
    public function findAtoz($field, $addresstable, $categories, $pages)
    {
        $query = $this->createQuery();

        // Make A-Z respect configured pages if there are some
        $where = "pid<>-1 ";
        if(strlen($pages) > 0)
            $where = "pid IN (".$pages.") ";

        // Standard constraints
        $languageAspect = GeneralUtility::makeInstance(Context::class)->getAspect('language');
        $currentLanguageUid = $languageAspect->getId();
        $where .= "AND deleted=0 AND hidden=0 AND sys_language_uid IN (-1,".$currentLanguageUid .") ";

        // Respect categories
        if ($categories && count($categories) > 0) {
            $where .= "AND (";
            foreach ($categories as $category) {
                $where .= "uid IN (SELECT uid_foreign FROM sys_category_record_mm ".
                                                     "WHERE uid_local='".$category->getUid()."' AND sys_category_record_mm.tablenames = '".$addresstable."' ".
                                                     "AND sys_category_record_mm.fieldname = 'categories') OR ";
            }
            $where .= "1=0 )";
        }

        $sql = 'select DISTINCT UPPER(LEFT('.$field.', 1)) as letter from ' . $addresstable . ' where ' . $where;

        $res = array();
        foreach($query->statement($sql)->execute(true) as $result) {
            $res[] = $result['letter'];
        }

        return $res;
    }

    /**
     * @return array
     */
    public function search($atozvalue, $atozField, $categories, $queryvalue, $queryFields, $distanceValue, $distanceField, $filterValue, $filterField)
    {
        $query = $this->createQuery();

        // Build A to Z constraint
        $constraints = array();
        if ($atozField && !($atozField === "none") && $atozvalue && strlen(trim($atozvalue)) === 1)
            $constraints[] = $query->like($atozField, $atozvalue.'%');

        // Build distance constraint
        if ($distanceField && !($distanceField === "none") && strlen($distanceValue) > 0) {
            $constraints[] = $query->logicalAnd($query->lessThanOrEqual($distanceField, (int)$distanceValue));
        }

        // Build query constraints
        if ($queryFields && count($queryFields) > 0 && $queryvalue && !($queryvalue === ""))
        {
            $queryconstraints = array();
            foreach ($queryFields as $field) {
                $queryconstraints[] = $query->like($field, '%'.$queryvalue.'%');
            }
            $constraints[] = $query->logicalOr($queryconstraints);
        }

        // Build category constraints
        if ($categories && count($categories) > 0)
        {
            $catconstraints = array();
            foreach ($categories as $category) {
                $catconstraints[] = $query->contains("categories", $category->getUid());
            }
            $constraints[] = $query->logicalOr($catconstraints);
        }

        // Build filter constraint
        if ($filterField && !($filterField === "none") && $filterValue && !($filterValue === "-1")) {
            if (strpos($filterField, '.') !== false) {
                // Filter via mmtable
                $filterField = substr($filterField, 0, strpos($filterField, '.'));
                $constraints[] = $query->contains($filterField, $filterValue);
            } else {
                // Filter via string
                $constraints[] = $query->equals($filterField, $filterValue);
            }
        }

        // Localization constraint
        $languageAspect = GeneralUtility::makeInstance(Context::class)->getAspect('language');
        $currentLanguageUid = $languageAspect->getId();
        $constraints[] = $query->in('sysLanguageUid', array(-1,$currentLanguageUid));

        if(count($constraints) < 1) {
            return $this->findAll();
        }

        $query->matching($query->logicalAnd($constraints));
        return $query->execute();
    }

    /**
     * Find all records with missing coordinates.
     * This method is used in the task.
     */
    public function findGeoLessEntries()
    {
        $query = $this->createQuery();
        $query->setLimit(10);

        $constraints = [
            $query->equals('latitude', null),
            $query->equals('longitude', null)
        ];

        return $query->matching($query->logicalOr($constraints))->execute();
    }

    /**
     *  Find all Map Markers matching current category selection
     *
     * @param $currentCategories
     * @return array|\TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     */
    public function findMapEntries($currentCategories)
    {
        $query = $this->createQuery();

        $constraints = array(
            $query->logicalNot(
                $query->logicalOr(
                    $query->equals('latitude', null),
                    $query->equals('longitude', null)
                )
            )
        );

        foreach ($currentCategories as $maincat) {
            $catConstraints = [];
            foreach ($maincat['children'] as $subcat) {
                if($subcat['active']) {
                    $catConstraints[] = $query->contains('categories', $subcat['uid']);
                }
            }
            if(!empty($catConstraints)) {
                $constraints[] = $query->logicalAnd(
                    $query->logicalOr($catConstraints)
                );
            }
        }

        $query->matching(
            $query->logicalAnd($constraints)
        );

        return $query->execute();
    }

    /**
     * Allow third parties like sic_calender to submit their own queries
     */
    public function findByConstraints($constraints)
    {
        $query = $this->createQuery();
        return $query->matching($constraints)->execute();
    }

    /**
     * Get all fields from tt_address
     */
    public function getFields() {
        $fields = array();

        if(!empty($GLOBALS['TCA']['tt_address']['columns'])) {
            foreach($GLOBALS['TCA']['tt_address']['columns'] as $field=>$config) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Find doublets in address entries
     *
     * @param array $fields The db fields to search for doublets.
     * @return void
     */
    public function findDoublets($fields) {
        $properties = [];
        foreach($fields as $field=>$active) {
            if(!empty($active)) {
                $properties[] = $field;
            }
        }
        if(!empty($properties)) {
            $selectProperties = [];
            foreach($properties as $property) {
                $selectProperties[] = 'IF('.$property.' IS NULL,"(NULL)",'.$property.') AS '.$property;
            }
            $selectProperties = implode(',', $selectProperties);
            $properties = implode(',', $properties);

            $query = $this->createQuery();
            $table = $query->getSource()->getSelectorName();
            $where = '1=1';
            if(empty($fields['hidden'])) {
                $where .= ' AND hidden=0';
            }
            if(empty($fields['deleted'])) {
                $where .= ' AND deleted=0';
            }

            $sql = '
            select
                count(uid) as total,'.$selectProperties.'
            from
                '.$table.'
            where
                '.$where.'
            group by
                '.$properties.'
                having total > 1
            order by
                deleted desc,
                hidden desc,
                total desc,' . $properties;

            $doublets = [];
            foreach($query->statement($sql)->execute(true) as $doublet) {
                $pid = empty($fields['pid']) ? 0 : $doublet['pid'];
                if(isset($doublet['pid'])) {
                    unset($doublet['pid']);
                }
                $doublets[$pid]['page'] = $this->getPageNameLabel($pid);
                $doublets[$pid]['datasets'][] = $doublet;
            }
            return $doublets;
        }

        return [];
    }

    /**
     * Get page name from its pid
     *
     * @param int $pid The pid value of the page
     * @return string
     */
    public function getPageNameLabel($pid = 0) {
        if(isset($this->pages[$pid])) {
            return $this->pages[$pid];
        }

        $query = $this->createQuery();
        $sql = 'select uid,hidden,deleted,title from pages where uid = '.abs($pid);

        $page = $query->statement($sql)->execute(true);
        $this->pages[$pid] = empty($page) ? [] : $page[0];

        return $this->pages[$pid];
    }

    /**
     * Find address entries matching the values of the given arguments
     *
     * @param array $args An array of fields with their values included; array('field1' => 'value1', 'field2' => 'value2', ...)
     * @return void
     */
    public function findByArgs($args) {
        $constraints  = array();
        $query = $this->createQuery();

        foreach($args as $field=>$value) {
            if( $value == '(NULL)' ) $value = '';

            if( !empty($value) && !in_array($field, array('action','controller')) ) {
                $property = GeneralUtility::underscoredToLowerCamelCase($field);
                $constraints[] = $query->equals($property, $value);
            }
        }

        $query->matching($query->logicalAnd($constraints));
        $query->getQuerySettings()->setRespectStoragePage(false);

        return $query->execute();
    }

    /**
     * Return the table of for query object
     *
     * @return void
     */
    public function getTable() {
        return $this->createQuery()->getSource()->getSelectorName();
    }

    /**
     * Check if given property is relevant.
     * - not a system field
     * - has not empty values
     *
     * @param array $property
     * @return boolean
     */
    public function isRelevant($property) {
        if(in_array($property, array('crdate','tstamp','l10n_diffsource'))) return false;

        $query = $this->createQuery();
        $table = $query->getSource()->getSelectorName();

        $sql = 'SELECT COUNT(' . $property . ') AS total FROM ' . $table . ' WHERE LENGTH(TRIM(' . $property . ')) > 0 AND TRIM(' . $property . ') != "0" GROUP BY ' . $property;
        $res = $query->statement($sql)->execute(true);

        return !empty($res[0]);
    }

    /**
     * Find address storages
     *
     * @return array
     */
    public function findPids() {
        $query = $this->createQuery();
        $table = $query->getSource()->getSelectorName();

        $sql = 'select uid,title from pages where uid in (select distinct pid from '.$table.')';

        $pids = array();
        foreach($query->statement($sql)->execute(true) as $item) {
            $pids[$item['uid']] = $item['title'];
        }

        return $pids;
    }

    /**
     * Retrieve a list of all countries
     *
     * @return array
     */
    public function findAllCountries()
    {
        $query = $this->createQuery();
        $table = $query->getSource()->getSelectorName();
        $sql = 'SELECT DISTINCT country FROM ' . $table . ';';

        $countries = array();
        foreach ($query->statement($sql)->execute(true) as $row) {
            $countries[$row['country']] = $row['country'];
        }

        return $countries;
    }

    /**
     * @return string
     */
    public function getFieldType($field)
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_sicaddress_domain_model_domainproperty');
        $queryBuilder = $connection->createQueryBuilder();
        $query = $queryBuilder->select('type')->from('tx_sicaddress_domain_model_domainproperty')->where('title = \''.$field.'\'');
        return $query->execute()->fetchAll()[0]['type'];
    }

    /**
     * @return array
     */
    public function getFilterArray($field)
    {
        $query = $this->createQuery();
        $table = $query->getSource()->getSelectorName();
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
        $queryBuilder = $connection->createQueryBuilder();
        $query = $queryBuilder->select($field)->from($table)->groupBy($field);
        return $query->execute()->fetchAll();
    }
}
