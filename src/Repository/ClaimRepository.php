<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\ClaimsBundle\Entity\Claim;

/**
 * @extends ServiceEntityRepository<Claim>
 */
final class ClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Claim::class);
    }

    /**
     * All claims for one subject, optionally restricted to a scope.
     *
     * @return list<Claim>
     */
    public function findForSubject(string $subjectType, string $subjectId, ?string $scope = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.subjectType = :st')->setParameter('st', $subjectType)
            ->andWhere('c.subjectId = :sid')->setParameter('sid', $subjectId)
            ->orderBy('c.predicate', 'ASC')
            ->addOrderBy('c.createdAt', 'ASC');

        if (null !== $scope) {
            $qb->andWhere('c.scope = :scope')->setParameter('scope', $scope);
        }

        /* @var list<Claim> */
        return $qb->getQuery()->getResult();
    }

    /**
     * All claims for every subject in a scope, in one query. Use this (grouping by
     * subjectId in-memory) instead of calling findForSubject() per row when
     * processing a whole dataset — see ClaimAggregator::aggregateAllForScope().
     *
     * @return list<Claim>
     */
    public function findForScope(string $subjectType, string $scope): array
    {
        /* @var list<Claim> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.subjectType = :st')->setParameter('st', $subjectType)
            ->andWhere('c.scope = :scope')->setParameter('scope', $scope)
            ->orderBy('c.subjectId', 'ASC')
            ->addOrderBy('c.predicate', 'ASC')
            ->addOrderBy('c.createdAt', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * All claims emitted by one tool for one subject. Used by the ingestor
     * to delete a prior run before writing a fresh one.
     *
     * @return list<Claim>
     */
    public function findForSubjectAndSource(string $subjectType, string $subjectId, string $source, ?string $scope = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.subjectType = :st')->setParameter('st', $subjectType)
            ->andWhere('c.subjectId = :sid')->setParameter('sid', $subjectId)
            ->andWhere('c.source = :src')->setParameter('src', $source);

        if (null !== $scope) {
            $qb->andWhere('c.scope = :scope')->setParameter('scope', $scope);
        }

        /* @var list<Claim> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Batch variant of findForSubjectAndSource() — one query for many subjectIds
     * sharing a (subjectType, source, scope) instead of one query per subject.
     * Used by ClaimIngestor::recordBatch() to avoid a stale-claims lookup per
     * item when ingesting claims for a whole batch (e.g. mediary's media:sync).
     *
     * @param list<string> $subjectIds
     *
     * @return array<string, list<Claim>> keyed by subjectId
     */
    public function findForSubjectsAndSource(string $subjectType, array $subjectIds, string $source, ?string $scope = null): array
    {
        $subjectIds = array_values(array_unique($subjectIds));
        if ([] === $subjectIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.subjectType = :st')->setParameter('st', $subjectType)
            ->andWhere('c.subjectId IN (:sids)')->setParameter('sids', $subjectIds)
            ->andWhere('c.source = :src')->setParameter('src', $source);

        if (null !== $scope) {
            $qb->andWhere('c.scope = :scope')->setParameter('scope', $scope);
        }

        $bySubject = [];
        /** @var Claim $row */
        foreach ($qb->getQuery()->getResult() as $row) {
            $bySubject[$row->subjectId][] = $row;
        }

        return $bySubject;
    }

    /** @return list<Claim> */
    public function findByRun(string $runId): array
    {
        /* @var list<Claim> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.runId = :rid')->setParameter('rid', $runId)
            ->orderBy('c.predicate', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Most-recently created claim for a given predicate and subject.
     */
    public function findLatestByPredicate(
        string $subjectType,
        string $subjectId,
        string $predicate,
        ?string $scope = null,
    ): ?Claim {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.subjectType = :st')->setParameter('st', $subjectType)
            ->andWhere('c.subjectId = :sid')->setParameter('sid', $subjectId)
            ->andWhere('c.predicate = :pred')->setParameter('pred', $predicate)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1);

        if (null !== $scope) {
            $qb->andWhere('c.scope = :scope')->setParameter('scope', $scope);
        }

        /* @var Claim|null */
        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return iterable<Claim>
     */
    public function iterateForExport(?string $scope = null, ?string $subjectType = null, ?string $source = null): iterable
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC');

        if (null !== $scope) {
            $qb->andWhere('c.scope = :scope')->setParameter('scope', $scope);
        }

        if (null !== $subjectType) {
            $qb->andWhere('c.subjectType = :subjectType')->setParameter('subjectType', $subjectType);
        }

        if (null !== $source) {
            $qb->andWhere('c.source = :source')->setParameter('source', $source);
        }

        return $qb->getQuery()->toIterable();
    }
}
