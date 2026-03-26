<?php

declare(strict_types=1);

namespace Brosua\FormPermission\Security;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Form\Domain\Repository\FormDefinitionRepository;
use TYPO3\CMS\Form\Domain\ValueObject\FormIdentifier;
use TYPO3\CMS\Form\Mvc\Persistence\Exception\PersistenceManagerException;

/**
 * Business-level permission checks for form records.
 *
 * Mirrors the checks of the core DatabasePermissionChecker (TCA table existence,
 * tables_select/tables_modify, web mount + PAGE_SHOW) and adds form-specific
 * rules on top (identifier allow-lists, record-level guards).
 */
final readonly class FormPermissionChecker
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
        private FormDefinitionRepository $formRepository,
    ) {
    }

    /**
     * Whether the current BE user may read a specific form record.
     *
     * Checks (in order):
     *   1. An authenticated BE user exists
     *   2. The form_definition table exists in TCA
     *   3. The user has tables_select access to form_definition
     *   4. The user has page-level access to the record's storage page
     */
    public function hasReadAccess(FormIdentifier $identifier): bool
    {
        return $this->hasRecordAccess($identifier, write: false);
    }

    /**
     * Whether the current BE user may write/delete a specific form record.
     *
     * Checks (in order):
     *   1. An authenticated BE user exists
     *   2. The form_definition table exists in TCA
     *   3. The user has tables_modify access to form_definition
     *   4. The user has page-level access to the record's storage page
     */
    public function hasWriteAccess(FormIdentifier $identifier): bool
    {
        return $this->hasRecordAccess($identifier, write: true);
    }

    /**
     * Whether the current BE user may create new form records at all.
     *
     * Checks (in order):
     *   1. An authenticated BE user exists
     *   2. The form_definition table exists in TCA
     *   3. The user has tables_modify access to form_definition
     *
     * Note: the page-level check for a specific target PID is performed
     * separately via DatabasePermissionChecker::hasWritePermission().
     */
    public function hasCreatePermission(): bool
    {
        if (!$this->hasBackendUser()) {
            return false;
        }
        if (!$this->tcaSchemaFactory->has(FormDefinitionRepository::TABLE_NAME)) {
            return false;
        }

        return $this->hasTableWriteAccess();
    }

    /** @throws PersistenceManagerException */
    public function assertReadAccess(FormIdentifier $identifier): void
    {
        if (!$this->hasReadAccess($identifier)) {
            throw new PersistenceManagerException(
                sprintf('Access denied: You do not have permission to read form "%s".', $identifier->identifier),
                1743000001
            );
        }
    }

    /** @throws PersistenceManagerException */
    public function assertWriteAccess(FormIdentifier $identifier): void
    {
        if (!$this->hasWriteAccess($identifier)) {
            throw new PersistenceManagerException(
                sprintf('Access denied: You do not have write permission for form "%s".', $identifier->identifier),
                1743000002
            );
        }
    }

    /**
     * Shared logic for read and write access checks.
     *
     * @param bool $write TRUE for write/modify check, FALSE for read/select check
     */
    private function hasRecordAccess(FormIdentifier $identifier, bool $write): bool
    {
        if (!$this->hasBackendUser()) {
            return false;
        }
        if (!$this->tcaSchemaFactory->has(FormDefinitionRepository::TABLE_NAME)) {
            return false;
        }
        if ($write ? !$this->hasTableWriteAccess() : !$this->hasTableReadAccess()) {
            return false;
        }

        // NEW* identifiers have no record yet – page-level check happens on write
        if (str_starts_with($identifier->identifier, 'NEW')) {
            return true;
        }
        if (!MathUtility::canBeInterpretedAsInteger($identifier->identifier)) {
            return false;
        }

        $record = $this->formRepository->findByUid((int)$identifier->identifier);
        if ($record === null) {
            return false;
        }

        return $this->hasPageAccess((int)($record['pid'] ?? 0));
    }

    /**
     * Page-level access check – mirrors DatabasePermissionChecker::hasPageAccess().
     *
     * Admins always pass. For pid <= 0 (root) access is granted.
     * For all other pages: web mount membership AND Permission::PAGE_SHOW are required.
     */
    private function hasPageAccess(int $pageId): bool
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        if ($pageId <= 0) {
            return true;
        }

        $pageRow = BackendUtility::getRecord('pages', $pageId);
        if ($pageRow === null) {
            return false;
        }
        if ($backendUser->isInWebMount($pageId) === null) {
            return false;
        }

        return $backendUser->doesUserHaveAccess($pageRow, Permission::PAGE_SHOW);
    }

    private function hasTableReadAccess(): bool
    {
        return $this->getBackendUser()?->check('tables_select', FormDefinitionRepository::TABLE_NAME) ?? false;
    }

    private function hasTableWriteAccess(): bool
    {
        return $this->getBackendUser()?->check('tables_modify', FormDefinitionRepository::TABLE_NAME) ?? false;
    }

    private function hasBackendUser(): bool
    {
        return ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication
            ? $GLOBALS['BE_USER']
            : null;
    }
}
