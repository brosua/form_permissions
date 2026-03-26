<?php

declare(strict_types=1);

namespace Brosua\FormPermission\Storage;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Form\Storage\Permission\DatabasePermissionChecker;

/**
 * Repository for form-related page lookups (form folders) and
 * form_definition batch queries used by the storage adapter.
 */
final readonly class FormRepository
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private DatabasePermissionChecker $permissionChecker,
    ) {
    }

    /**
     * Returns all pages with module='forms' that the current BE user may write to.
     *
     * @return array<int, array{uid: int, title: string, pid: int}>
     */
    public function findAccessibleFolders(): array
    {
        $rows = $this->queryFormFolders();
        $accessible = [];

        foreach ($rows as $row) {
            $pid = (int)$row['uid'];
            if ($this->permissionChecker->hasWritePermission($pid)) {
                $accessible[] = $row;
            }
        }

        return $accessible;
    }

    /**
     * Returns true when the given page UID has module='forms' set.
     * The page doktype is intentionally not checked – any page type is allowed.
     * Does NOT check user permissions – use hasWritePermission separately.
     */
    public function isFormFolder(int $pageUid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        $count = $queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('module', $queryBuilder->createNamedParameter('forms'))
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$count > 0;
    }

    /**
     * Returns a formUid → pid map for the given form_definition UIDs.
     *
     * @param list<int> $formUids
     * @return array<int, int>  formUid => pid
     */
    public function resolvePidsForFormUids(array $formUids): array
    {
        if ($formUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('form_definition');

        $rows = $queryBuilder
            ->select('uid', 'pid')
            ->from('form_definition')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($formUids, ArrayParameterType::INTEGER)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['uid']] = (int)$row['pid'];
        }

        return $map;
    }

    /** @return array<int, array{uid: int, title: string, pid: int}> */
    private function queryFormFolders(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        /** @var array<int, array{uid: int, title: string, pid: int}> $rows */
        $rows = $queryBuilder
            ->select('uid', 'title', 'pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('module', $queryBuilder->createNamedParameter('forms'))
            )
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row) => [
                'uid' => (int)$row['uid'],
                'title' => (string)$row['title'],
                'pid' => (int)$row['pid'],
            ],
            $rows
        );
    }
}
