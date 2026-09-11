<?php

namespace App\Mail;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['message_id', 'kind', 'email', 'name', 'position'])]
class Address extends Model
{
    public $timestamps = false;

    protected $table = 'mail_addresses';

    public function label(): string
    {
        return $this->name ? "{$this->name} <{$this->email}>" : $this->email;
    }
}
