<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WebauthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webauthn\Bundle\Repository\CredentialRecordRepositoryInterface;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * @extends ServiceEntityRepository<WebauthnCredential>
 */
class WebauthnCredentialRepository extends ServiceEntityRepository implements CredentialRecordRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebauthnCredential::class);
    }

    /**
     * @return array<CredentialRecord>
     */
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        return $this->findBy(['userHandle' => $publicKeyCredentialUserEntity->id]);
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?CredentialRecord
    {
        return $this->findOneBy(['publicKeyCredentialId' => $publicKeyCredentialId]);
    }

    public function save(WebauthnCredential $credential): void
    {
        $em = $this->getEntityManager();
        $em->persist($credential);
        $em->flush();
    }

    public function remove(WebauthnCredential $credential): void
    {
        $em = $this->getEntityManager();
        $em->remove($credential);
        $em->flush();
    }
}
