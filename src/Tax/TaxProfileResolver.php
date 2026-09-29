<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tax;

use Supla\EnergyCostCalculator\Exception\DefinitionException;

final class TaxProfileResolver
{
    public function __construct(
        private readonly TaxProfileAssignmentCatalog $assignmentCatalog = new TaxProfileAssignmentCatalog(),
        private readonly TaxProfileCatalog $profileCatalog = new TaxProfileCatalog(),
    ) {
    }

    /**
     * @return list<array{validFrom: ?\DateTimeImmutable, validTo: ?\DateTimeImmutable, profileId: string, profile: TaxProfile}>
     */
    public function resolve(
        TaxContext $context,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        ?string $currency = null,
    ): array {
        if ($from !== null && $to !== null && $from >= $to) {
            throw new DefinitionException('Tax profile resolution range must be ordered.');
        }

        $matches = array_values(array_filter(
            $this->assignmentCatalog->assignments(),
            static fn(TaxProfileAssignment $assignment): bool => $assignment->taxContext->equals($context),
        ));
        usort($matches, static fn(TaxProfileAssignment $a, TaxProfileAssignment $b): int =>
            ($a->validFrom?->getTimestamp() ?? PHP_INT_MIN) <=> ($b->validFrom?->getTimestamp() ?? PHP_INT_MIN));

        if ($matches === []) {
            throw new DefinitionException(sprintf(
                'Unknown tax context %s/%s.',
                $context->jurisdiction,
                $context->customerClass,
            ));
        }

        $resolved = [];
        foreach ($matches as $assignment) {
            $clippedFrom = $this->maxDate($assignment->validFrom, $from);
            $clippedTo = $this->minDate($assignment->validTo, $to);
            if ($clippedFrom !== null && $clippedTo !== null && $clippedFrom >= $clippedTo) {
                continue;
            }
            if ($to !== null && $clippedFrom !== null && $clippedFrom >= $to) {
                continue;
            }
            if ($from !== null && $clippedTo !== null && $clippedTo <= $from) {
                continue;
            }

            $profile = $this->profileCatalog->get($assignment->profileId);
            if ($currency !== null && $profile->currency !== $currency) {
                throw new DefinitionException("Tax profile '{$profile->id}' has incompatible currency '$profile->currency'; expected '$currency'.");
            }
            $resolved[] = [
                'validFrom' => $clippedFrom,
                'validTo' => $clippedTo,
                'profileId' => $assignment->profileId,
                'profile' => $profile,
            ];
        }

        if ($resolved === []) {
            throw new DefinitionException('Tax profile assignments do not cover the requested range.');
        }

        $cursor = $from;
        foreach ($resolved as $index => $entry) {
            if (!$this->sameBoundary($entry['validFrom'], $cursor)) {
                $kind = $cursor !== null && $entry['validFrom'] !== null && $entry['validFrom'] < $cursor ? 'overlap' : 'gap';
                throw new DefinitionException("Tax profile assignment $kind at resolved segment $index.");
            }
            $cursor = $entry['validTo'];
        }
        if (!$this->sameBoundary($cursor, $to)) {
            throw new DefinitionException('Tax profile assignment gap at the end of the requested range.');
        }

        return $resolved;
    }

    private function sameBoundary(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): bool
    {
        return $left === null ? $right === null : $right !== null && $left == $right;
    }

    private function maxDate(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): ?\DateTimeImmutable
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }
        return $left > $right ? $left : $right;
    }

    private function minDate(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): ?\DateTimeImmutable
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }
        return $left < $right ? $left : $right;
    }
}
