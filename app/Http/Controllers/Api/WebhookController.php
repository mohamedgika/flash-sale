<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    protected $idempotencyRepository;

    public function __construct(\App\Interfaces\IdempotencyRepositoryInterface $idempotencyRepository)
    {
        $this->idempotencyRepository = $idempotencyRepository;
    }

    public function handle(Request $request)
    {
        return $this->idempotencyRepository->handle($request);
    }
}
