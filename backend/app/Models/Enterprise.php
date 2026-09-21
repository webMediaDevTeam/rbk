<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enterprise extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'email',
        'tax_number',
        'phone',
        'address',
        'logo',
        'status',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'enterprise_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }
}
