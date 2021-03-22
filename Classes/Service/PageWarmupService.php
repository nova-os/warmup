<?php
declare(strict_types=1);
namespace B13\Warmup\Service;

/*
 * This file is part of TYPO3 CMS-based extension "warmup" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\Warmup\FrontendRequestBuilder;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * @todo: clean this class up, allow multi-language via SiteLanguage
 */
class PageWarmupService
{
    private $io;

    /**
     * @var array
     */
    private $excludePages = [];

    public function __construct($excludePages = [])
    {
        $this->excludePages = $excludePages;
    }

    public function warmUp(SymfonyStyle $io)
    {
        $this->io = $io;

        // fetch all pages which are not deleted and in live workspace and not one of excluded types
        $excludeDocTypes = [
            3, // external link
            4, // shortcut
            6, // be user section
            7, // mount point
            199, // menu separator
            254, // folder
            255 // recycler
        ];

        $excludePages = [];
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('cf_cache_pages_tags');
        $pagesInCache = $queryBuilder->select('*')->from('cf_cache_pages_tags')->where(
            $queryBuilder->expr()->like(
                'tag',
                '\'pageId_%\''
            )
        )->execute();

        while ($pidInCache = $pagesInCache->fetch()) {
            $excludePages[] = preg_replace('/^pageId_/', '', $pidInCache['tag']);
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class))
            ->add(GeneralUtility::makeInstance(HiddenRestriction::class))
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $statement = $queryBuilder->select('*')->from('pages')->where(
            $queryBuilder->expr()->notIn('doktype', $excludeDocTypes),
            $queryBuilder->expr()->notIn('uid', $excludePages),
            $queryBuilder->expr()->eq('sys_language_uid', 0)
        )->setMaxResults(100)->execute();

        $io->writeln('Starting to request pages at ' . date('d.m.Y H:i:s'));
        $requestedPages = 0;

        while ($pageRecord = $statement->fetch()) {
            if (in_array((int)$pageRecord['uid'], $this->excludePages, true)) {
                continue;
            }
            try {
                $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId((int)$pageRecord['uid']);
                $languageUid = (int)$pageRecord['sys_language_uid'];
                $siteLanguage = $site->getLanguageById($languageUid);
                $url = $site->getRouter()->generateUri($pageRecord, ['_language' => $siteLanguage]);
                $this->executeRequestForPageRecord($url, $pageRecord);
                $requestedPages++;

            } catch (SiteNotFoundException $e) {
                $io->error('Rootline Cache for Page ID ' . $pageRecord['uid'] . ' could not be warmed up');
            }
        }

        $io->writeln('Finished requesting ' . $requestedPages . ' pages at ' . date('d.m.Y H:i:s'));
    }

    /**
     * @param UriInterface $url
     * @param array $pageRecord
     */
    protected function executeRequestForPageRecord(UriInterface $url, array $pageRecord)
    {
        Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
        Bootstrap::initializeBackendAuthentication();
        Bootstrap::initializeBackendRouter();

        $userGroups = $this->resolveRequestedUserGroupsForPage($pageRecord);

        $this->io->writeln('Calling ' . (string)$url . ' (Page ID: ' . $pageRecord['uid'] . ', UserGroups: ' . implode(',', $userGroups) . ')');

        try {
            $builder = new FrontendRequestBuilder();
            $builder->buildRequestForPage($url, 13, $userGroups);
        } catch (\Throwable $e) {
            $this->io->error('Could not request page: ' . $e->getMessage() . "\n");
        }

    }

    protected function resolveRequestedUserGroupsForPage(array $pageRecord)
    {
        $userGroups = $pageRecord['fe_group'];
        $rootLine = GeneralUtility::makeInstance(RootlineUtility::class, $pageRecord['uid'])->get();
        foreach ($rootLine as $pageInRootLine) {
            if ($pageInRootLine['extendToSubpages']) {
                $userGroups .= ',' . $pageInRootLine['fe_group'];
            }
        }
        $userGroups = GeneralUtility::intExplode(',', $userGroups, true);
        $userGroups = array_filter($userGroups);
        $userGroups = array_unique($userGroups);
        return $userGroups;
    }
}
