<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Target Connection
    |--------------------------------------------------------------------------
    |
    | The database connection (defined in config/database.php) that relay
    | payloads are written to. This is the remote "server" database.
    |
    */

    'connection' => env('SYNC_CONNECTION', 'server'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Tables
    |--------------------------------------------------------------------------
    |
    | Because the target table name arrives inside the request payload, only
    | tables listed here may be written to. This is the primary guard against
    | a malicious/incorrect payload writing to an arbitrary table.
    |
    | Each entry maps a table name to the column(s) that make up its primary
    | (unique) key. Writes are insert-only (insertOrIgnore): existing rows are
    | never updated, and rows whose key already exists are skipped, which keeps
    | retried/duplicate payloads idempotent instead of erroring or duplicating.
    |
    */

    'tables' => [
        'Transaction_Items' => ['Transaction_ID', 'POS_ID', 'Branch_ID', 'Item_Number'],
        'Transactions' => ['Transaction_ID', 'POS_ID', 'Branch_ID'],  
        'Transaction_Details' => ['Branch_ID','Transaction_ID','POS_ID'],
        'Transaction_History_MedalofValor' => ['ID','Transaction_ID'],
        'Transaction_History_NationalAthlete' => ['ID','Transaction_ID'],
        'Transaction_History_PWD' => ['ID','Transaction_ID'],
        'Transaction_History_SeniorCitizen' => ['ID','Transaction_ID'],
        'Transaction_History_SoloParent' => ['ID','Transaction_ID'],
        'Business_Day' => ['PERIOD_ID','POS_ID'],
        'turnovercashiermophistory' => ['BRANCH_ID','OUTLETID','DATE','SHIFT','EMPNO','POSNUMBER','MOP_ID'],
        'Periods' => ['Period_ID','POS_ID','Branch_ID'],
        'CashDraw_Denomination' => ['ID'],
        'Cashier_History' => ['Cashier_ID','Period_ID','POS_ID'],
        'DailySummary' => ['STATIONCODE','DATE'],
        'Days' => ['DATE'],
        'Departments' => ['Department_ID'],
        'DiscountSummary' => ['BRANCHID','OUTLETID','POSNUMBER','DATE','EMPNO','SHIFT','DISCOUNTID','RESETID'],
        'Eod_Reports' => ['POS_ID','PERIOD_ID'],
        'OutletStockStatusFilter' => ['BRANCHID'],
        'Finalisation_History' => ['MOP_ID','PERIOD_ID'],
        'stockcrd' => ['BRANCHID','STOCKCRDID','OUTLETID','POSNUMBER'],
        'Synctable' => ['TABLENAME'],
       
        
    

    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Behaviour
    |--------------------------------------------------------------------------
    |
    | How many times a relay job is attempted before it is moved to the
    | failed_jobs table, and the per-attempt backoff (in seconds).
    |
    */

    'tries' => env('SYNC_TRIES', 5),

    'backoff' => [10, 30, 60, 120, 300],

    'timeout' => env('SYNC_TIMEOUT', 120),

    'queue' => env('SYNC_QUEUE', 'relay'),

];
