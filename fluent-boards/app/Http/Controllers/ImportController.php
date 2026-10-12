<?php

namespace FluentBoards\App\Http\Controllers;

use Exception;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\JsonImportService;

/** Expose administrator-only JSON import endpoints for the free plugin. */
class ImportController extends Controller
{
    /** Import a complete uploaded JSON export. */
    public function importFile(Request $request)
    {
        return $this->runImport('importFile', $request);
    }

    /** Stage one chunk of a JSON export. */
    public function uploadJsonChunk(Request $request)
    {
        return $this->runImport('uploadJsonChunk', $request);
    }

    /** Initialize or resume a paginated board import. */
    public function processJsonImport(Request $request)
    {
        return $this->runImport('processJsonImport', $request);
    }

    /** Delegate import work and translate validation failures into API errors. */
    private function runImport($method, Request $request)
    {
        $service = new JsonImportService(function ($source, $data) {
            return $this->importExternalSource($source, $data);
        });

        try {
            return $this->sendSuccess($service->$method($request));
        } catch (Exception $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    /** Allow the Pro controller to supply third-party JSON importers. */
    protected function importExternalSource($source, $importData)
    {
        return null;
    }
}
