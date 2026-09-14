<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCspReportJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CspReportController extends Controller
{
    public function store(Request $request): Response
    {
        ProcessCspReportJob::dispatch(
            $request->getContent(),
            $request->header('Content-Type'),
        );

        return response()->noContent();
    }
}
