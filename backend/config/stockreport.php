<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Which report column carries the ERP system quantity
    |--------------------------------------------------------------------------
    |
    | PENDING (Q5). The business has not yet confirmed whether the quantity it
    | calls "QTY" is LOWERQTY or HIGHERQTY. The Stock Report offers both and no
    | column literally named QTY, which is the whole of the ambiguity.
    |
    | Until it is settled the mapping stays on LOWERQTY, exactly as it has been.
    | When the answer arrives this value is the only thing that changes — set it
    | to 'higherqty' and re-import. No code, schema or report has to move.
    |
    | Note for whoever makes that change: if it becomes 'higherqty', system
    | quantity and whole quantity are then the same figure, and `whole_qty`
    | becomes redundant rather than wrong. Worth confirming that is intended
    | before flipping it.
    |
    */
    'system_quantity_column' => env('STOCK_REPORT_SYSTEM_QTY_COLUMN', 'lowerqty'),

];
