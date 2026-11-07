<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'fullname',
        'name',
        'role_category',
    ];

    public function role_users()
    {
        return $this->hasMany(RoleUser::class);
    }

    public function staff_assigned_role()
    {
        return $this->hasMany(StaffAssignedRole::class);
    }

}
