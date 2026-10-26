<?php

namespace App\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records every create, update and delete of the model in the audit log.
 * Hidden attributes are logged as changed without revealing their values.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            /** @var Model&self $model */
            AuditLog::record($model, 'created', [], $model->auditValues($model->getAttributes()));
        });

        static::updated(function (Model $model) {
            /** @var Model&self $model */
            $new = $model->auditValues($model->getChanges());

            if ($new === []) {
                return;
            }

            $old = $model->auditValues(array_intersect_key($model->getRawOriginal(), $new));

            AuditLog::record($model, 'updated', $old, $new);
        });

        static::deleted(function (Model $model) {
            /** @var Model&self $model */
            AuditLog::record($model, 'deleted', $model->auditValues($model->getRawOriginal()), []);
        });
    }

    /**
     * Attributes that are never written to the audit log.
     *
     * @return list<string>
     */
    protected function auditIgnored(): array
    {
        return ['created_at', 'updated_at', 'remember_token', 'last_login_at'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditValues(array $attributes): array
    {
        $values = array_diff_key($attributes, array_flip($this->auditIgnored()));

        foreach (array_intersect_key($values, array_flip($this->getHidden())) as $key => $value) {
            $values[$key] = '[hidden]';
        }

        return $values;
    }
}
