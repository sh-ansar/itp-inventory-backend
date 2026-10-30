<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CompanyMembership extends Model {
 public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Company::class); }
 protected $fillable = ['user_id','company_id','role_code'];
}