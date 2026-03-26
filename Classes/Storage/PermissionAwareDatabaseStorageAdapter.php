<?php

declare(strict_types=1);

namespace Brosua\FormPermission\Storage;

use Brosua\FormPermission\Security\FormPermissionChecker;
use TYPO3\CMS\Backend\Utility\BackendUtility;
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

    public function read(FormIdentifier $identifier): FormData
    {
        $this->formPermissionChecker->assertReadAccess($identifier);
        return $this->inner->read($identifier);
    }

    public function write(FormIdentifier $identifier, FormData $data, ?StorageContext $context = null): FormIdentifier
    {
        if (str_starts_with($identifier->identifier, 'NEW')) {
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

        $this->formPermissionChecker->assertWriteAccess($identifier);
        return $this->inner->write($identifier, $data, $context);
    }

    public function delete(FormIdentifier $identifier): void
    {
        $this->formPermissionChecker->assertWriteAccess($identifier);
        $this->inner->delete($identifier);
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
