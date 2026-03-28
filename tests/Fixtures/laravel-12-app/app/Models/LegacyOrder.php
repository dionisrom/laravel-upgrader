<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model using deprecated Model::unguard() pattern (removed in L13).
 * DeprecatedApiRemover should flag this for manual review.
 */
class LegacyOrder extends Model
{
    public function fillFromRequest(array $data): static
    {
        // Deprecated pattern: Model::unguard() + fill + Model::reguard()
        // Should be replaced with: $this->forceFill($data);
        Model::unguard();
        $this->fill($data);
        Model::reguard();

        return $this;
    }
}
