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
    

        'Transaction_Items' => ['primaryKey'=> ['Transaction_ID', 'POS_ID', 'Branch_ID', 'Item_Number'],'function' => 'insert'],
        'Transactions' => ['primaryKey'=> ['Transaction_ID', 'POS_ID', 'Branch_ID'],'function' => 'insert'],  
        'Transaction_Details' => ['primaryKey'=> ['Branch_ID','Transaction_ID','POS_ID'],'function' => 'insert'],
        'Transaction_History_MedalofValor' => ['primaryKey'=> ['ID','Transaction_ID'],'function' => 'insert'],
        'Transaction_History_NationalAthlete' =>['primaryKey'=>  ['ID','Transaction_ID'],'function' => 'insert'],
        'Transaction_History_PWD' => ['primaryKey'=> ['ID','Transaction_ID'],'function' => 'insert'],
        'Transaction_History_SeniorCitizen' => ['primaryKey'=> ['ID','Transaction_ID'],'function' => 'insert'],
        'Transaction_History_SoloParent' =>['primaryKey'=> ['ID','Transaction_ID'],'function' => 'insert'],
        'Business_Day' => ['primaryKey'=> ['PERIOD_ID','POS_ID'],'function' => 'upsert'],
        'turnovercashiermophistory' => ['primaryKey'=> ['BRANCH_ID','OUTLETID','DATE','SHIFT','EMPNO','POSNUMBER','MOP_ID'],'function' => 'insert'],
        'Periods' => ['primaryKey'=> ['Period_ID','POS_ID','Branch_ID'],'function' => 'upsert'],
        'CashDraw_Denomination' => ['primaryKey'=> ['ID'],'function' => 'insert'],
        'Cashier_History' =>['primaryKey'=>  ['Cashier_ID','Period_ID','POS_ID'],'function' => 'insert'],
        'DailySummary' =>['primaryKey'=>  ['STATIONCODE','DATE'],'function' => 'insert'],
        'Days' =>['primaryKey'=>  ['DATE'],'function' => 'insert'],
        'Departments' =>['primaryKey'=>  ['Department_ID'],'function' => 'insert'],
        'DiscountSummary' =>['primaryKey'=>  ['BRANCHID','OUTLETID','POSNUMBER','DATE','EMPNO','SHIFT','DISCOUNTID','RESETID'],'function' => 'insert'],
        'Eod_Reports' => ['primaryKey'=> ['POS_ID','PERIOD_ID'],'function' => 'insert'],
        'OutletStockStatusFilter' =>['primaryKey'=>  ['BRANCHID'],'function' => 'insert'],
        'Finalisation_History' =>['primaryKey'=>  ['MOP_ID','PERIOD_ID'],'function' => 'insert'],
        'stockcrd' =>['primaryKey'=>  ['BRANCHID','STOCKCRDID','OUTLETID','POSNUMBER'],'function' => 'insert'],
        'Synctable' =>['primaryKey'=>  ['TABLENAME'],'function' => 'insert'],
    
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
