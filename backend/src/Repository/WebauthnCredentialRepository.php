<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webauthn\Bundle\Repository\CanSaveCredentialRecord;
use Webauthn\Bundle\Repository\CredentialRecordRepositoryInterface;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * @extends ServiceEntityRepository<WebauthnCredential>
 */
class WebauthnCredentialRepository extends ServiceEntityRepository implements
    CredentialRecordRepositoryInterface,
    CanSaveCredentialRecord
{
    public function __construct(ManagerRegistry $registry, private readonly UserRepository $users)
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

    /**
     * Called by the bundle once a ceremony validates. An existing record is
     * updated in place so the signature counter advances; a new one is
     * attached to the account named by the user handle.
     */
    public function saveCredentialRecord(CredentialRecord $credentialRecord): void
    {
        $em = $this->getEntityManager();

        if ($credentialRecord instanceof WebauthnCredential) {
            $em->persist($credentialRecord);
            $em->flush();

            return;
        }

        $existing = $this->findOneBy(['publicKeyCredentialId' => $credentialRecord->publicKeyCredentialId]);

        if ($existing instanceof WebauthnCredential) {
            $existing->counter = $credentialRecord->counter;
            $em->flush();

            return;
        }

        $user = $this->users->find($credentialRecord->userHandle);

        if (!$user instanceof User) {
            throw new \RuntimeException(sprintf(
                'No account matches WebAuthn user handle "%s".',
                $credentialRecord->userHandle,
            ));
        }

        $credential = WebauthnCredential::fromRecord($credentialRecord, $user, 'Passkey');

        $em->persist($credential);
        $em->flush();
    }

    public function findNewestForUser(User $user): ?WebauthnCredential
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function remove(WebauthnCredential $credential): void
    {
        $em = $this->getEntityManager();
        $em->remove($credential);
        $em->flush();
    }
}
