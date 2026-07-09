<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Feature tests boot the full application via Tests\TestCase. Unit tests stay
| framework-free (plain assertions) for speed.
*/
pest()->extend(TestCase::class)->in('Feature');
