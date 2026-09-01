<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Support\HasPublicId;
use Database\Factories\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address a customer keeps so they do not retype it.
 *
 * A convenience, never a record. An order copies these values onto itself
 * at placement and reads its own copy forever after — editing or deleting
 * one of these must not be able to rewrite where a placed order was sent,
 * which is why nothing downstream of checkout holds a foreign key to it.
 *
 * `state` is nullable: Singapore, Malta and the Vatican have none, and
 * requiring one is a US-shaped assumption dressed up as validation.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string|null $label
 * @property string $name
 * @property string $line1
 * @property string|null $line2
 * @property string $city
 * @property string|null $state
 * @property string $postcode
 * @property string $country
 * @property string|null $phone
 * @property bool $is_default
 */
final class CustomerAddress extends Model
{
    /** @use HasFactory<CustomerAddressFactory> */
    use HasFactory;

    use HasPublicId;

    protected $fillable = [
        'user_id', 'label', 'name', 'line1', 'line2',
        'city', 'state', 'postcode', 'country', 'phone', 'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
