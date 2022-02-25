<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $guarded = ['id','created_at','updated_at'];
   // protected $this = ['client'];
    public function client(){
        return $this->belongsTo(Client::class,'client_id','id');
    }
}
