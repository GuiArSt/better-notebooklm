<?php

return [

    /*
    | The embedding model is fixed: changing it means re-embedding every passage.
    | The dimension below is checked against a real vector by a test (step 2 pins it).
    */
    'model' => env('EMBEDDING_MODEL', 'mistral-embed'),

    'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 1024),

];
