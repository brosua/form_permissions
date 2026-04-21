<?php

declare(strict_types=1);

namespace Brosua\FormPermission\Storage;

use Brosua\FormPermission\Security\FormPermissionChecker;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Form\Domain\DTO\FormData;
use TYPO3\CMS\Form\Domain\DTO\FormMetadata;
use TYPO3\CMS\Form\Domain\DTO\SearchCriteria;
use TYPO3\CMS\Form\Domain\DTO\StorageContext;
use TYPO3\CMS\Form\Domain\Repository\FormDefinitionRepository;
use TYPO3\CMS\Form\Domain\ValueObject\FormIdentifier;
use TYPO3\CMS\Form\Mvc\Persistence\Exception\PersistenceManagerException;
use TYPO3\CMS\Form\Storage\DatabaseStorageAdapter;
use TYPO3\CMS\Form\Storage\Permission\DatabasePermissionChecker;
use TYPO3\CMS\Form\Storage\StorageAdapterInterface;

final readonly class PermissionAwareDatabaseStorageAdapter implements StorageAdapterInterface
{
    public function __construct(
        private DatabaseStorageAdapter $inner,
        private FormDefinitionRepository $repository,
        private DatabasePermissionChecker $permissionChecker,
        private FormRepository $formRepository,
        private FormPermissionChecker $formPermissionChecker,
    ) {
    }

    public function read(FormIdentifier $identifier, ?ServerRequestInterface $request = null): FormData
    {
        // In frontend context no backend permission checks are needed (no BE session).
        // In all other contexts the custom permission checker is applied INSTEAD of
        // delegating to inner first – this avoids loading the record twice.
        $applicationType = $request !== null ? ApplicationType::fromRequest($request) : null;
        if (!$applicationType?->isFrontend()) {
            $this->formPermissionChecker->assertReadAccess($identifier);
        }
        // Delegate to inner which handles JSON parsing, record loading and its own
        // permission check.  The double-load is acceptable here because read() must
        // return the full parsed FormData which only inner can build.
        return $this->inner->read($identifier, $request);
    }

    public function write(FormIdentifier $identifier, FormData $data, ?StorageContext $context = null): FormIdentifier
    {
        // --- NEW form -----------------------------------------------------------
        if (!$this->exists($identifier)) {
            $pid = $context?->pid ?? 0;

            if (!$this->isAllowedStorageLocation((string)$pid)) {
                throw new PersistenceManagerException(
                    sprintf('Storage location "%d" is not allowed or you do not have write permission.', $pid),
                    1743000010
                );
            }

            $uid = $this->repository->add($identifier->identifier, $pid, $data);
            if (!$uid) {
                throw new PersistenceManagerException('Failed to create form definition in database.', 1743000011);
            }

            return new FormIdentifier((string)$uid);
        }

        // --- Existing form – single record load for permission + update ---------
        $uid = $this->extractUidOrThrow($identifier);
        $record = $this->repository->findByUid($uid);
        if ($record === null) {
            throw new PersistenceManagerException(
                sprintf('The form with uid "%d" could not be found.', $uid),
                1743000012
            );
        }

        $this->formPermissionChecker->assertWriteAccessForRecord($uid, $record);

        $result = $this->repository->update($uid, $data);
        if (!$result) {
            throw new PersistenceManagerException(
                sprintf('Failed to update form definition with uid "%d".', $uid),
                1743000013
            );
        }

        return $identifier;
    }

    public function delete(FormIdentifier $identifier): void
    {
        // Single record load for permission check + delete
        $uid = $this->extractUidOrThrow($identifier);
        $record = $this->repository->findByUid($uid);
        if ($record === null) {
            throw new PersistenceManagerException(
                sprintf('The form with uid "%d" could not be found.', $uid),
                1743000014
            );
        }

        $this->formPermissionChecker->assertWriteAccessForRecord($uid, $record);

        $success = $this->repository->remove($uid);
        if (!$success) {
            throw new PersistenceManagerException(
                sprintf('Failed to delete form definition with uid "%d".', $uid),
                1743000015
            );
        }
    }

    public function exists(FormIdentifier $identifier): bool
    {
        // inner.exists() already runs the full DatabasePermissionChecker chain
        // (TCA table, tables_select, web mount, PAGE_SHOW); no second check needed.
        return $this->inner->exists($identifier);
    }

    public function existsByFormIdentifier(string $formIdentifier): bool
    {
        return $this->inner->existsByFormIdentifier($formIdentifier);
    }

    /** @return array<FormMetadata> */
    public function findAll(SearchCriteria $criteria): array
    {
        $forms = $this->inner->findAll($criteria);

        if ($forms === []) {
            return [];
        }

        $uids = array_values(array_filter(array_map(
            static fn (FormMetadata $f) => MathUtility::canBeInterpretedAsInteger($f->persistenceIdentifier ?? '')
                ? (int)$f->persistenceIdentifier
                : null,
            $forms
        )));

        $pidMap = $this->formRepository->resolvePidsForFormUids($uids);

        return array_map(function (FormMetadata $form) use ($pidMap): FormMetadata {
            if (!MathUtility::canBeInterpretedAsInteger($form->persistenceIdentifier ?? '')) {
                return $form;
            }
            $uid = (int)$form->persistenceIdentifier;
            if (!isset($pidMap[$uid])) {
                return $form;
            }
            return $form->withStorageLocation($this->buildStorageLocationLabel($pidMap[$uid]));
        }, $forms);
    }

    public function getFormManagerOptions(): array
    {
        if (!$this->formPermissionChecker->hasCreatePermission()) {
            return [];
        }

        $locations = [];

        if ($this->permissionChecker->hasWritePermission(0)) {
            $locations[] = ['value' => '0', 'label' => $this->buildStorageLocationLabel(0)];
        }

        foreach ($this->formRepository->findAccessibleFolders() as $folder) {
            $locations[] = [
                'value' => (string)$folder['uid'],
                'label' => $this->buildStorageLocationLabel($folder['uid']),
            ];
        }

        return $locations === [] ? [] : ['allowedStorageLocations' => $locations];
    }

    public function isAccessible(): bool
    {
        return $this->inner->isAccessible();
    }

    public function isAllowedStorageLocation(string $storageLocation): bool
    {
        if (!MathUtility::canBeInterpretedAsInteger($storageLocation)) {
            return false;
        }

        $pid = (int)$storageLocation;

        if ($pid === 0) {
            return $this->permissionChecker->hasWritePermission(0);
        }

        return $this->formRepository->isFormFolder($pid)
            && $this->permissionChecker->hasWritePermission($pid);
    }

    public function isAllowedPersistenceIdentifier(string $persistenceIdentifier): bool
    {
        return $this->inner->isAllowedPersistenceIdentifier($persistenceIdentifier);
    }

    public function getTypeIdentifier(): string
    {
        return $this->inner->getTypeIdentifier();
    }
    public function supports(string $identifier): bool
    {
        return $this->inner->supports($identifier);
    }
    public function getPriority(): int
    {
        return $this->inner->getPriority();
    }
    public function getLabel(): string
    {
        return $this->inner->getLabel();
    }
    public function getDescription(): string
    {
        return $this->inner->getDescription();
    }
    public function getIconIdentifier(): string
    {
        return $this->inner->getIconIdentifier();
    }

    public function getUniquePersistenceIdentifier(string $formIdentifier, string $storageLocation): string
    {
        return $this->inner->getUniquePersistenceIdentifier($formIdentifier, $storageLocation);
    }

    /**
     * @throws PersistenceManagerException
     */
    private function extractUidOrThrow(FormIdentifier $identifier): int
    {
        if (!MathUtility::canBeInterpretedAsInteger($identifier->identifier)) {
            throw new PersistenceManagerException(
                sprintf('Invalid database identifier "%s". Expected numeric UID.', $identifier->identifier),
                1743000016
            );
        }
        return (int)$identifier->identifier;
    }

    /**
     * Builds the full rootline path for display (e.g. "/Forms/Contact/").
     * pid = 0 has no pages record – the TYPO3 site name is used as root segment.
     */
    private function buildStorageLocationLabel(int $pid): string
    {
        if ($pid === 0) {
            $siteName = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? '');
            return $siteName !== '' ? '/' . $siteName . '/' : '/';
        }

        $pagePath = [];
        foreach (array_reverse(BackendUtility::BEgetRootLine($pid)) as $page) {
            if ((int)$page['uid'] !== 0) {
                $pagePath[] = $page['title'];
            }
        }

        return '/' . implode('/', $pagePath) . '/';
    }
}
