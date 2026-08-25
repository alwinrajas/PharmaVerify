<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Verification of a completed count.
 *
 * A submitted audit stays editable, but only for users holding the
 * verification permission, and every change is written to the audit trail with
 * the old and the new value so the correction can always be explained.
 */
class VerificationService
{
    /** Fields a verifier is allowed to correct on a submitted line. */
    private const EDITABLE_FIELDS = [
        'physical_qty',
        'batch',
        'expiry_date',
        'shelf_location',
        'remarks',
    ];

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateLine(AuditLine $line, array $changes, User $user, bool $markVerified = true): AuditLine
    {
        $audit = $line->audit;

        if ($audit && $audit->status === Audit::STATUS_CLOSED) {
            throw new BusinessRuleException('This audit has been closed and can no longer be edited.');
        }

        $applied = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            if (! array_key_exists($field, $changes)) {
                continue;
            }

            $newValue = $changes[$field];
            $oldValue = $line->getAttribute($field);

            $oldComparable = $field === 'expiry_date' ? $oldValue?->toDateString() : $oldValue;

            if ($field === 'physical_qty') {
                $oldComparable = (float) $oldValue;
                $newValue = (float) $newValue;
            }

            if ($oldComparable == $newValue) {
                continue;
            }

            $line->setAttribute($field, $newValue);

            $applied[$field] = [
                'old' => $oldComparable instanceof \DateTimeInterface ? $oldComparable->format('Y-m-d') : $oldComparable,
                'new' => $newValue,
            ];
        }

        if ($applied === [] && ! $markVerified) {
            return $line;
        }

        return DB::transaction(function () use ($line, $applied, $user, $markVerified, $audit) {
            // Physical quantity drives the variance, so it is always recomputed
            // rather than trusted from the request.
            $line->recalculateVariance();

            if ($markVerified) {
                $line->verification_status = AuditLine::VERIFICATION_VERIFIED;
                $line->verified_by = $user->id;
                $line->verified_at = now();
            }

            $line->save();

            if ($audit) {
                $this->refreshAuditCounters($audit);
            }

            if ($applied !== []) {
                activity('verification')
                    ->causedBy($user)
                    ->performedOn($line)
                    ->withProperties([
                        'audit_id' => $line->audit_id,
                        'product_code' => $line->product_code,
                        'batch' => $line->batch,
                        'changes' => $applied,
                    ])
                    ->log('Audit line corrected during verification');
            } elseif ($markVerified) {
                activity('verification')
                    ->causedBy($user)
                    ->performedOn($line)
                    ->withProperties([
                        'audit_id' => $line->audit_id,
                        'product_code' => $line->product_code,
                    ])
                    ->log('Audit line verified');
            }

            return $line->fresh(['verifiedBy']);
        });
    }

    /**
     * Marks every outstanding line of an audit as verified in one action.
     */
    public function verifyAudit(Audit $audit, User $user): Audit
    {
        if ($audit->lines()->count() === 0) {
            throw new BusinessRuleException('This audit does not contain any lines to verify.');
        }

        return DB::transaction(function () use ($audit, $user) {
            $audit->lines()
                ->where('verification_status', '!=', AuditLine::VERIFICATION_VERIFIED)
                ->update([
                    'verification_status' => AuditLine::VERIFICATION_VERIFIED,
                    'verified_by' => $user->id,
                    'verified_at' => now(),
                    'updated_at' => now(),
                ]);

            $audit->update([
                'status' => Audit::STATUS_VERIFIED,
                'verified_by' => $user->id,
                'verified_at' => now(),
            ]);

            $this->refreshAuditCounters($audit);

            activity('verification')
                ->causedBy($user)
                ->performedOn($audit)
                ->withProperties([
                    'shop' => $audit->shop?->shop_code,
                    'device' => $audit->device?->device_code,
                    'audit_number' => $audit->audit_number,
                ])
                ->log('Audit verified');

            return $audit->fresh(['shop', 'device', 'verifiedBy']);
        });
    }

    /**
     * Keeps the audit header in step with its lines.
     */
    public function refreshAuditCounters(Audit $audit): void
    {
        $lines = $audit->lines();

        $total = (clone $lines)->count();
        $withVariance = (clone $lines)->where('variance_qty', '!=', 0)->count();
        $pending = (clone $lines)->where('verification_status', AuditLine::VERIFICATION_PENDING)->count();
        $adjusted = (clone $lines)->where('adjustment_status', AuditLine::ADJUSTMENT_ADJUSTED)->count();

        $status = match (true) {
            $audit->status === Audit::STATUS_CLOSED => Audit::STATUS_CLOSED,
            $adjusted > 0 && $pending === 0 => Audit::STATUS_ADJUSTED,
            $pending === 0 => Audit::STATUS_VERIFIED,
            $pending < $total => Audit::STATUS_IN_VERIFICATION,
            default => Audit::STATUS_SUBMITTED,
        };

        $audit->forceFill([
            'item_count' => $total,
            'variance_count' => $withVariance,
            'status' => $status,
        ])->save();
    }
}
