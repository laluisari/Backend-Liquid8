<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use App\Models\Sale;
use App\Models\Bundle;
use App\Models\Category;
use Brick\Math\BigInteger;
use App\Models\New_product;
use Illuminate\Support\Facades\Log;
use App\Models\SaleDocument;
use Illuminate\Http\Request;
use App\Http\Resources\ResponseResource;
use App\Models\Buyer;
use App\Models\Notification;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SaleDocumentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');
        $saleDocuments = SaleDocument::with('user:id,name')->where('status_document_sale', 'selesai')->latest();
        if ($query) {
            $saleDocuments = $saleDocuments->where(function ($data) use ($query) {
                $data->where('code_document_sale', 'LIKE', '%' . $query . '%')
                    ->orWhere('buyer_name_document_sale', 'LIKE', '%' . $query . '%');
            });
        }
        $saleDocuments = $saleDocuments->paginate(10);
        $resource = new ResponseResource(true, "list document sale", $saleDocuments);
        return $resource->response();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'code_document_sale' => 'required|unique:sale_documents',
                'buyer_name_document_sale'  => 'required',
                'total_product_document_sale' => 'required',
                'total_price_document_sale' => 'required',
                'voucher' => 'numeric|nullable'
            ]
        );

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }
        try {
            $request['user_id'] = auth()->id();
            $saleDocument = SaleDocument::create($request->all());
            $resource = new ResponseResource(true, "Data berhasil ditambahkan!", $saleDocument);
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal ditambahkan!", $e->getMessage());
        }
        return $resource->response();
    }

    /**
     * Display the specified resource.
     */
    public function show(SaleDocument $saleDocument)
    {
        $resource = new ResponseResource(true, "data document sale", $saleDocument->load('sales', 'user'));
        return $resource->response();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, SaleDocument $saleDocument)
    {
        $validator = Validator::make($request->all(), [
            'cardbox_qty' => 'required|numeric',
            'cardbox_unit_price' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        if (
            $request->cardbox_qty == $saleDocument->cardbox_qty &&
            $request->cardbox_unit_price == $saleDocument->cardbox_unit_price
        ) {
            $resource = new ResponseResource(false, "Data tidak ada yang berubah!", $saleDocument->load('sales', 'user'));
        } else {
            $saleDocument->update([
                'cardbox_qty' => $request->cardbox_qty,
                'cardbox_unit_price' => $request->cardbox_unit_price,
                'cardbox_total_price' => $request->cardbox_qty * $request->cardbox_unit_price,
            ]);

            $resource = new ResponseResource(true, "Data berhasil disimpan!", $saleDocument->load('sales', 'user'));
        }

        return $resource->response();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SaleDocument $saleDocument)
    {
        try {
            $saleDocument->delete();
            $resource = new ResponseResource(true, "data berhasil di hapus!", $saleDocument);
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "data gagal di hapus!", $e->getMessage());
        }
        return $resource->response();
    }

    public function saleFinish(Request $request)
    {
        try {
            DB::beginTransaction();
            $userId = $request->user()->id;
            $saleDocument = SaleDocument::where('status_document_sale', 'proses')
                ->where('user_id', $userId)
                ->first();

            if ($saleDocument == null) {
                throw new Exception("Data sale belum dibuat!");
            }

            $validator = Validator::make($request->all(), [
                'voucher' => 'nullable|numeric',
                'cardbox_qty' => 'nullable|numeric|required_with:cardbox_unit_price',
                'cardbox_unit_price' => 'nullable|numeric|required_with:cardbox_qty',
            ]);

            if ($validator->fails()) {
                return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
            }


            $sales = Sale::where('code_document_sale', $saleDocument->code_document_sale)->get();

            // Inisialisasi approved dokumen sebagai '0'

            $approved = '0';
            if ($request->filled('voucher')) {
                foreach ($sales as $sale) {
                    if ($sale->display_price > $sale->product_price_sale) {
                        // Update hanya sales yang memenuhi kondisi
                        $sale->update(['approved' => '1']);
                        $approved = '1';
                    } else {
                        // Sales yang tidak memenuhi kondisi tetap '0'
                        $sale->update(['approved' => '0']);
                    }
                }
                
            } else {
                foreach ($sales as $sale) {
                    if ($sale->display_price > $sale->product_price_sale) {
                        $sale->update(['approved' => '1']);
                        $approved = '1';
                    } else {
                        $sale->update(['approved' => '0']);
                        $approved = '0';
                    }
                }
            }
            if($request->input('voucher') !== '0'){
                $approved = '1';
            }
            // Update dokumen dan buat notifikasi jika ada sales yang approved
            if ($approved === '1') {
                Notification::create([
                    'user_id' => $userId,
                    'notification_name' => 'approve discount sale',
                    'status' => 'sale',
                    'role' => 'Spv',
                    'external_id' => $saleDocument->id
                ]);

                $saleDocument->update(['approved' => '1']);
            }


            $totalDisplayPrice = Sale::where('code_document_sale', $saleDocument->code_document_sale)->sum('display_price');

            $totalProductPriceSale = Sale::where('code_document_sale', $saleDocument->code_document_sale)->sum('product_price_sale');

            $totalProductPriceSale = $request['voucher'] ? $totalProductPriceSale - $request['voucher'] : $totalProductPriceSale;

            $totalProductOldPriceSale = Sale::where('code_document_sale', $saleDocument->code_document_sale)->sum('product_old_price_sale');

            // Ambil barcodes dari $sales
            $productBarcodes = $sales->pluck('product_barcode_sale');

            // Hapus semua New_product yang sesuai
            New_product::whereIn('new_barcode_product', $productBarcodes)->delete();

            // Update semua Bundle yang sesuai menjadi 'sale'
            Bundle::whereIn('barcode_bundle', $productBarcodes)->update(['product_status' => 'sale']);

            // Batch update status pada $sales
            $sales->each->update(['status_sale' => 'selesai']);

            $saleDocument->update([
                'total_product_document_sale' => count($sales),
                'total_old_price_document_sale' => $totalProductOldPriceSale,
                'total_price_document_sale' => $totalProductPriceSale,
                'total_display_document_sale' => $totalDisplayPrice,
                'status_document_sale' => 'selesai',
                'cardbox_qty' => $request->cardbox_qty ?? 0,
                'cardbox_unit_price' => $request->cardbox_unit_price ?? 0,
                'cardbox_total_price' => $request->cardbox_qty * $request->cardbox_unit_price ?? 0,
                'voucher' => $request->input('voucher'),
                'approved' => $approved,
                'is_tax' => $request->input('is_tax') ?? 0,
                'tax' => $request->input('tax') ?? null,
                'price_after_tax' => $request->input('price_after_tax') ?? null
            ]);

            $avgPurchaseBuyer = SaleDocument::where('status_document_sale', 'selesai')
                ->where('buyer_id_document_sale', $saleDocument->buyer_id_document_sale)
                ->avg('total_price_document_sale');

            $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);
            $saleDocumentCountWithBuyerId = SaleDocument::where('buyer_id_document_sale', $buyer->id)->count();

            if ($saleDocumentCountWithBuyerId == 2 || $saleDocumentCountWithBuyerId == 3) {
                $typeBuyer = 'Repeat';
            } else if ($saleDocumentCountWithBuyerId > 3) {
                $typeBuyer = 'Reguler';
            }

            $buyer->update([
                'type_buyer' => $typeBuyer ?? "Biasa",
                'amount_transaction_buyer' => $buyer->amount_transaction_buyer + 1,
                'amount_purchase_buyer' => number_format($buyer->amount_purchase_buyer + $saleDocument->total_price_document_sale, 2, '.', ''),
                'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', ''),
            ]);

            logUserAction($request, $request->user(), "outbound/sale/kasir", "Menekan tombol sale");

            DB::commit();
            $resource = new ResponseResource(true, "Data berhasil disimpan!", $saleDocument);
        } catch (\Exception $e) {
            DB::rollBack();
            $resource = new ResponseResource(false, "Data gagal disimpan!", $e->getMessage());
            return $resource->response()->setStatusCode(500);
        }
        return $resource->response();
    }

    public function addProductSaleInDocument(Request $request)
    {
        DB::beginTransaction();
        // $userId = auth()->id();

        $validator = Validator::make(
            $request->all(),
            [
                'sale_barcode' => 'required',
                'sale_document_id' => 'required|numeric',
            ]
        );

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        try {

            $saleDocument = SaleDocument::find($request->sale_document_id);

            if (!$saleDocument) {
                return (new ResponseResource(false, "sale_document_id tidak di temukan!", []))->response()->setStatusCode(404);
            }

            $productSale = Sale::where('product_barcode_sale', $request->input('sale_barcode'))->first();
            if ($productSale) {
                $resource = new ResponseResource(false, "Data sudah dimasukkan!", $productSale);
                return $resource->response()->setStatusCode(422);
            }

            $newProduct = New_product::where('new_barcode_product', $request->sale_barcode)->first();
            $bundle = Bundle::where('barcode_bundle', $request->sale_barcode)->first();

            if (!$newProduct && !$bundle) {
                return response()->json(['error' => 'Both new product and bundle not found'], 404);
            }

            if ($newProduct) {
                $data = [
                    $newProduct->new_name_product,
                    $newProduct->new_category_product,
                    $newProduct->new_barcode_product,
                    $newProduct->display_price,
                    $newProduct->new_price_product,
                    $newProduct->new_discount,
                    $newProduct->old_price_product,
                ];
            } elseif ($bundle) {
                $data = [
                    $bundle->name_bundle,
                    $bundle->category,
                    $bundle->barcode_bundle,
                    $bundle->total_price_custom_bundle,
                    $bundle->total_price_bundle,
                ];
            } else {
                return (new ResponseResource(false, "Barcode tidak ditemukan!", []))->response()->setStatusCode(404);
            }

            if (!$newProduct) {
                $bundle->product_status = 'sale';
            } elseif (!$bundle) {
                $newProduct->delete();
            } else {
                $newProduct->delete();
                $bundle->product_status = 'sale';
            }

            $sale = Sale::create(
                [
                    'user_id' => auth()->id(),
                    'code_document_sale' => $saleDocument->code_document_sale,
                    'product_name_sale' => $data[0],
                    'product_category_sale' => $data[1],
                    'product_barcode_sale' => $data[2],
                    'product_old_price_sale' => $data[6] ?? $data[4],
                    'product_price_sale' => $data[3],
                    'product_qty_sale' => 1,
                    'status_sale' => 'selesai',
                    'total_discount_sale' => $data[4] - $data[3],
                    'new_discount' => $data[5] ?? NULL,
                    'display_price' => $data[3],
                ]
            );

            $saleDocument->update([
                'total_product_document_sale' => $saleDocument->total_product_document_sale + 1,
                'total_old_price_document_sale' => ($data[6] ?? $data[4]) + $saleDocument->total_old_price_document_sale,
                'total_price_document_sale' => $data[3] + $saleDocument->total_price_document_sale,
                'total_display_document_sale' => $data[3] + $saleDocument->total_display_document_sale,
                // 'voucher' => $request->input('voucher')
            ]);


            $avgPurchaseBuyer = SaleDocument::where('status_document_sale', 'selesai')
                ->where('buyer_id_document_sale', $saleDocument->buyer_id_document_sale)
                ->avg('total_price_document_sale');

            $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

            $buyer->update([
                'amount_purchase_buyer' => number_format($buyer->amount_purchase_buyer + $saleDocument->total_price_document_sale, 2, '.', ''),
                'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', ''),
            ]);

            DB::commit();
            return new ResponseResource(true, "data berhasil di tambahkan!", $saleDocument->load('sales', 'user'));
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Data gagal ditambahkan!", $e->getMessage()))->response()->setStatusCode(500);
        }
    }

    public function deleteProductSaleInDocument(SaleDocument $saleDocument, Sale $sale)
    {
        DB::beginTransaction();
        try {

            $allSale = Sale::where('code_document_sale', $saleDocument->code_document_sale)
                ->where('status_sale', 'selesai')
                ->get();

            $saleDocument->update([
                'total_product_document_sale' => $saleDocument->total_product_document_sale - 1,
                'total_old_price_document_sale' => $saleDocument->total_old_price_document_sale - $sale->product_old_price_sale,
                'total_price_document_sale' => $saleDocument->total_price_document_sale - $sale->product_price_sale,
                'total_display_document_sale' => $saleDocument->total_display_document_sale - $sale->display_price,
            ]);

            $avgPurchaseBuyer = SaleDocument::where('status_document_sale', 'selesai')
                ->where('buyer_id_document_sale', $saleDocument->buyer_id_document_sale)
                ->avg('total_price_document_sale');

            $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

            $buyer->update([
                'amount_purchase_buyer' => number_format($buyer->amount_purchase_buyer - $sale->product_price_sale, 2, '.', ''),
                'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', ''),
            ]);

            //cek apabila di dalam document sale sudah tidak ada produk sale lagi
            if ($allSale->count() <= 1) {
                $buyer->update([
                    'amount_transaction_buyer' => $buyer->amount_transaction_buyer - 1,
                ]);
                $saleDocument->delete();
            }
            $sale->delete();
            $bundle = Bundle::where('barcode_bundle', $sale->product_barcode_sale)->first();
            if (!empty($bundle)) {
                $bundle->product_status = 'not sale';
            } else {
                $lolos = json_encode(['lolos' => 'lolos']);
                New_product::insert([
                    'code_document' => $sale->code_document,
                    'old_barcode_product' => $sale->product_barcode_sale,
                    'new_barcode_product' => $sale->product_barcode_sale,
                    'new_name_product' => $sale->product_name_sale,
                    'new_quantity_product' => $sale->product_qty_sale,
                    'new_price_product' => $sale->product_old_price_sale,
                    'old_price_product' => $sale->product_old_price_sale,
                    'new_date_in_product' => $sale->created_at,
                    'new_status_product' => 'display',
                    'new_quality' => $lolos,
                    'new_category_product' => $sale->product_category_sale,
                    'new_tag_product' => null,
                    'created_at' => $sale->created_at,
                    'updated_at' => $sale->updated_at,
                    'new_discount' => 0,
                    'display_price' => $sale->product_price_sale
                ]);
            }

            $resource = new ResponseResource(true, "data berhasil di hapus", $saleDocument->load('sales', 'user'));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            $resource = new ResponseResource(false, "data gagal di hapus", $e->getMessage());
        }
        return $resource->response();
    }

    public function combinedReport(Request $request)
    {
        $user = auth()->user();
        $name_user = $user->name;
        $codeDocument = $request->input('code_document_sale');
        $saleDocument = SaleDocument::where('code_document_sale', $codeDocument)->first();

        if (!$saleDocument) {
            return response()->json([
                'data' => null,
                'message' => 'Dokumen penjualan tidak ditemukan',
            ], 404);
        }

        $timezone = 'Asia/Jakarta';
        $currentTransactionTime = Carbon::parse($saleDocument->created_at)->timezone($timezone);

        $totalTransactionsBeforeCurrent = SaleDocument::whereDate('created_at', $currentTransactionTime->toDateString())
            ->where('created_at', '<', $currentTransactionTime)
            ->count();

        $pembeliKeBerapa = $totalTransactionsBeforeCurrent + 1;

        $categoryReport = $this->generateCategoryReport($saleDocument);
        // $barcodeReport = $this->generateBarcodeReport($saleDocument);

        return response()->json([
            'data' => [
                'name_user' => $name_user,
                'transactions_today' => $pembeliKeBerapa,
                'category_report' => $categoryReport,
                // 'NameBarcode_report' => $barcodeReport,
            ],
            'message' => 'Laporan penjualan',
            'buyer' => $saleDocument
        ]);
    }

    private function generateCategoryReport($saleDocument)
    {
        $totalPrice = 0;
        $oldPrice = 0;
        $categoryReport = [];
        $categories = collect();

        foreach ($saleDocument->sales as $sale) {
            $category = Category::where('name_category', $sale->product_category_sale)->first();
            if ($category) {
                $categories->push($category);
            }
        }
        if ($saleDocument->sales->count() > 0) {
            $groupedSales = $saleDocument->sales->groupBy(function ($sale) {
                return $sale->product_category_sale ? strtoupper($sale->product_category_sale) : 'Unknown';
            });

            foreach ($groupedSales as $categoryName => $group) {
                $totalPricePerCategory = $group->sum(function ($sale) {
                    return $sale->product_qty_sale * $sale->product_price_sale;
                });

                $PriceBeforeDiscount = $group->sum(function ($sale) {
                    return $sale->product_old_price_sale;
                });
                $oldPrice += $PriceBeforeDiscount;
                $totalPrice += $totalPricePerCategory;

                // Menemukan kategori dari koleksi secara manual
                $category = null;
                foreach ($categories as $cat) {
                    if ($cat->name_category === $categoryName) {
                        $category = $cat;
                        break;
                    }
                }

                $categoryReport[] = [
                    'category' => $categoryName,
                    'total_quantity' => $group->sum('product_qty_sale'),
                    'total_price' => ceil($totalPricePerCategory),
                    'before_discount' => ceil($PriceBeforeDiscount),
                    'total_discount' => $category ? $category->discount_category : null,
                ];
            }
        }

        return ["category_list" => $categoryReport, 'total_harga' => ceil($totalPrice), 'total_price_before_discount' => ceil($oldPrice)];
    }



    private function generateBarcodeReport($saleDocument)
    {
        $report = [];
        $totalPrice = 0;

        foreach ($saleDocument->sales as $index => $sale) {
            $productName = $sale->product_name_sale;
            $productBarcode = $sale->product_barcode_sale;
            $productPrice = $sale->product_price_sale;
            $productQty = $sale->product_qty_sale;

            $subtotalPrice = $productPrice * $productQty;

            $report[] = [
                $index + 1,
                $productName,
                $productBarcode,
                $subtotalPrice,
            ];

            $totalPrice += $subtotalPrice;
        }

        $report[] = ['Total Harga', $totalPrice];

        return $report;
    }

    public function get_approve_discount($id_sale_document)
    {
        $check_approved = SaleDocument::where('id', $id_sale_document)
            ->where('approved', '1')
            ->exists();

        if (!$check_approved) {
            return (new ResponseResource(false, "Document is not approved", null))->response()->setStatusCode(404);
        }

        $document_sale = SaleDocument::select(
            'id',
            'code_document_sale',
            'new_discount_sale',
            'total_product_document_sale',
            'total_price_document_sale',
            'total_display_document_sale',
            'voucher',
            'approved',
            'created_at'
        )->where('id', $id_sale_document)
            ->with([
                'sales' => function ($query) {
                    $query->whereColumn('product_price_sale', '<', 'display_price')
                        ->select(
                            'id',
                            'code_document_sale',
                            'product_name_sale',
                            'product_old_price_sale',
                            'product_category_sale',
                            'product_barcode_sale',
                            'product_price_sale',
                            'product_qty_sale',
                            'total_discount_sale',
                            'created_at',
                            'new_discount_sale',
                            'display_price',
                            'code_document',
                            'approved'
                        );
                }
            ])->first();

        return new ResponseResource(true, "approved invoice discount", $document_sale);
    }

    public function approvedDocument($id_sale_document)
    {
        $document_sale = SaleDocument::where('id', $id_sale_document)
            ->with(['sales' => function ($query) {
                $query->whereColumn('product_price_sale', '<', 'display_price');
            }])->first();

        if (!$document_sale) {
            return (new ResponseResource(false, "Sale document tidak ditemukan!", null))->response()->setStatusCode(404);
        }

        $document_sale->approved = '2';
        $document_sale->save();

        Sale::where('code_document_sale', $document_sale->code_document_sale)
            ->whereColumn('product_price_sale', '<', 'display_price')
            ->update(['approved' => '2']);

        $notif = Notification::where('status', 'sale')->where('external_id', $id_sale_document)->first();
        if (!$notif) {
            return (new ResponseResource(false, "Notification tidak tidak ditemukan!", null))->response()->setStatusCode(404);
        }
        $notif->update(['approved' => '2']);

        return new ResponseResource(true, "Approved berhasil di approve", $document_sale->code_document_sale);
    }

    public function approvedProduct($id_sale)
    {
        $sale = Sale::where('id', $id_sale)->where('approved', '1')->first();

        if (!$sale) {
            return (new ResponseResource(false, "Product tidak ditemukan!", null))->response()->setStatusCode(404);
        }

        $sale->approved = '2';
        $sale->save();
        $response = [
            'code_document_sale' => $sale->code_document_sale,
            'product_name_sale' => $sale->product_name_sale,
            'product_category_sale' => $sale->product_category_sale,
            'product_barcode_sale' => $sale->product_barcode_sale,
            'product_price_sale' => $sale->product_price_sale,
            'display_price' => $sale->display_price,
            'approved' => $sale->approved
        ];

        return new ResponseResource(true, "berhasil approve", $response);
    }

    public function rejectProduct($id_sale)
    {
        // Cek apakah produk ini sedang dalam status diskon
        $sale = Sale::where('id', $id_sale)
            ->where(function ($query) {
                $query->where('approved', '1')
                    ->orWhere('approved', '2');
            })
            ->first();

        if (!$sale) {
            return (new ResponseResource(false, "Product tidak ditemukan!", null))->response()->setStatusCode(404);
        }

        $saleDocument = SaleDocument::where('code_document_sale', $sale->code_document_sale)->first();

        // Simpan total harga lama sebelum update
        $oldTotalPrice = $saleDocument->total_price_document_sale;

        // Update sale dengan harga asli dan ubah status approved jadi 0
        $sale->approved = '0';
        $sale->product_price_sale = $sale->display_price;
        $sale->save();

        // Hitung total baru:
        // 1. Total dari produk yang masih berstatus diskon (approved 1 atau 2)
        $totalDiscountedPrice = Sale::where('code_document_sale', $sale->code_document_sale)
            ->where(function ($query) {
                $query->where('approved', '1')
                    ->orWhere('approved', '2');
            })
            ->sum('product_price_sale');

        // 2. Total dari produk yang tidak ada diskon (approved 0)
        $totalNonDiscountedPrice = Sale::where('code_document_sale', $sale->code_document_sale)
            ->where('approved', '0')
            ->sum('product_price_sale');

        // Total keseluruhan dikurangi voucher
        $newTotalPrice = $totalDiscountedPrice + $totalNonDiscountedPrice;
        $newTotalPriceAfterVoucher = $newTotalPrice - $saleDocument->voucher;

        // Update total harga di sale document
        $saleDocument->total_price_document_sale = $newTotalPriceAfterVoucher;
        $saleDocument->save();

        // Update buyer purchase amount
        $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

        // Hitung rata-rata pembelian
        $avgPurchaseBuyer = SaleDocument::where('buyer_id_document_sale', $buyer->id)
            ->avg('total_price_document_sale');

        // Update amount purchase buyer:
        // 1. Kurangi dulu total lama
        // 2. Tambahkan total baru
        $buyer->update([
            'amount_purchase_buyer' => number_format(
                ($buyer->amount_purchase_buyer - $oldTotalPrice) + $newTotalPriceAfterVoucher,
                2,
                '.',
                ''
            ),
            'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', '')
        ]);

        $response = [
            'code_document_sale' => $sale->code_document_sale,
            'product_name_sale' => $sale->product_name_sale,
            'product_category_sale' => $sale->product_category_sale,
            'product_barcode_sale' => $sale->product_barcode_sale,
            'product_price_sale' => $sale->product_price_sale,
            'display_price' => $sale->display_price,
            'approved' => $sale->approved,
            'total_price_document_sale' => $saleDocument->total_price_document_sale,
            'total_display_document_sale' => $saleDocument->total_display_document_sale,
            'grand_total' => $saleDocument->grand_total
        ];

        return new ResponseResource(true, "Berhasil reject discount", $response);
    }

    public function rejectAllDiscounts($id_sale_document)
    {
        // Ambil dokumen penjualan
        $saleDocument = SaleDocument::where('id', $id_sale_document)->first();

        if (!$saleDocument) {
            return (new ResponseResource(false, "Dokumen penjualan tidak ditemukan!", null))->response()->setStatusCode(404);
        }

        // Simpan total harga lama untuk perhitungan amount purchase buyer
        $oldTotalPrice = $saleDocument->total_price_document_sale;

        try {
            DB::beginTransaction();

            // Update semua sale yang memiliki diskon (approved 1 atau 2)
            $updatedSales = Sale::where('code_document_sale', $saleDocument->code_document_sale)
                ->where(function ($query) {
                    $query->where('approved', '1');
                })
                ->get();

            // Update setiap produk yang memiliki diskon
            foreach ($updatedSales as $sale) {
                $sale->approved = '0';
                $sale->product_price_sale = $sale->display_price;
                $sale->save();
            }

            // Hitung total baru dari semua produk
            $newTotalPrice = Sale::where('code_document_sale', $saleDocument->code_document_sale)
                ->sum('product_price_sale');

            // Reset voucher jika ada
            $saleDocument->voucher = 0;

            // Update total di dokumen penjualan
            $saleDocument->total_price_document_sale = $newTotalPrice;
            $saleDocument->approved = '0';
            $saleDocument->save();

            // Update buyer purchase amount
            $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

            // Hitung rata-rata pembelian baru
            $avgPurchaseBuyer = SaleDocument::where('buyer_id_document_sale', $buyer->id)
                ->avg('total_price_document_sale');

            // Update amount purchase buyer
            $buyer->update([
                'amount_purchase_buyer' => number_format(
                    ($buyer->amount_purchase_buyer - $oldTotalPrice) + $newTotalPrice,
                    2,
                    '.',
                    ''
                ),
                'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', '')
            ]);

            $notif = Notification::where('status', 'sale')->where('external_id', $id_sale_document)->first();
            if (!$notif) {
                return (new ResponseResource(false, "Notification tidak tidak ditemukan!", null))->response()->setStatusCode(404);
            }
            $notif->update(['approved' => '1']);

            DB::commit();

            // Siapkan response
            $response = [
                'code_document_sale' => $saleDocument->code_document_sale,
                'total_products_updated' => $updatedSales->count(),
                'total_price_document_sale' => $saleDocument->total_price_document_sale,
                'total_display_document_sale' => $saleDocument->total_display_document_sale,
                'grand_total' => $saleDocument->grand_total,
                'updated_products' => $updatedSales->map(function ($sale) {
                    return [
                        'product_name_sale' => $sale->product_name_sale,
                        'product_category_sale' => $sale->product_category_sale,
                        'product_barcode_sale' => $sale->product_barcode_sale,
                        'old_price' => $sale->getOriginal('product_price_sale'),
                        'new_price' => $sale->product_price_sale,
                        'display_price' => $sale->display_price
                    ];
                })
            ];

            return new ResponseResource(true, "Berhasil reject semua diskon", $response);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Gagal reject diskon: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }

    public function doneApproveDiscount($id_sale_document)
    {
        if (empty($id_sale_document)) {
            return (new ResponseResource(false, "id tidak ada", null))->response()->setStatusCode(404);
        }

        try {
            $saleDocument = SaleDocument::where('id', $id_sale_document)
                ->update(['approved' => '0']);

            if (!$saleDocument) {
                return (new ResponseResource(false, "gagal memperbarui data", null))->response()->setStatusCode(500);
            }

            $notif = Notification::where('status', 'sale')->where('external_id', $id_sale_document)->first();
            if (!$notif) {
                return (new ResponseResource(false, "Notification tidak tidak ditemukan!", null))->response()->setStatusCode(404);
            }
            $notif->update(['approved' => '2']);

            return new ResponseResource(true, "berhasil approve document", null);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
