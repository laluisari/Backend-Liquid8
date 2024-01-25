<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\New_product;
use App\Models\Palet;
use Illuminate\Http\Request;

class PaletController extends Controller
{

    public function display(){
        $new_products = New_product::where('new_status_product', 'display')->get();
        return new ResponseResource(true, "data produk display", $new_products);
    }

    public function index()
    {
        $palets = Palet::latest()->paginate(100);
        return new ResponseResource(true, "list palet", $palets);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        
    }

    /**
     * Display the specified resource.
     */
    public function show(Palet $palet)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Palet $palet)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Palet $palet)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Palet $palet)
    {
        //
    }
}
