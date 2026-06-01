<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'Transactions';

    public $timestamps = false;


    protected $fillable = [
        'Cashier_ID',
        'Sub_Account_ID',
        'POS_ID',
        'Transaction_Number',
        'Transaction_Date',
        '',
        '',
        '',
    ]


}
