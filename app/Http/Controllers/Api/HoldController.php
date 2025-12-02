<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Repositories\HoldRepository;
use App\Models\Hold;
class HoldController extends Controller
{
    protected $holdRepository;
    public function __construct(HoldRepository $holdRepository)
    {
        $this->holdRepository = $holdRepository;
    }
    public function store(Request $request)
    {
        return $this->holdRepository->store($request);
    }
}
