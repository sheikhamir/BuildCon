<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\InquiryOrder;
use App\Models\Item;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Barryvdh\DomPDF\Facade as PDF;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class QuotationController extends Controller
{
    public function index()
    {
        $select = [
            'quotations.id',
            'customers.customer_name',
            'quotations.project_name',
            'quotations.date',
            'quotations.total',
            'quotations.terms_condition',
            'users.name'
        ];
        $quotations = Quotation::select($select)
            ->where('quotations.user_id',Auth::user()->id)
            ->leftJoin('quotation_item', 'quotation_item.quotation_id', '=', 'quotations.id')
            ->leftJoin('brands', 'brands.id', '=', 'quotation_item.brand_id')
            ->leftJoin('items', 'items.id', '=', 'quotation_item.item_id')
            ->leftJoin('users','users.id','=','quotations.user_id')
            ->leftJoin('customers', 'customers.id', '=', 'quotations.customer_id')
            ->groupBy('quotations.id')->paginate($this->count);

        $data = [
            'title'     => 'Quotations',
            'user'      => Auth::user(),
            'quotations' => $quotations
        ];
        return view('sale.quotation.customer',$data);
    }

    public function add()
    {
        $customers = Customer::orderBy('id','DESC')->get();
        $brands    = Brand::orderBy('id','DESC')->get();
        $items     = Item::select([
            DB::raw("DISTINCT item_name"),
        ])->orderBy('id','DESC')->get();

        $data = [
            'title'    => 'Submit Quotation',
            'base_url' => env('APP_URL', 'http://127.0.0.1:8000'),
            'user'     => Auth::user(),
            'brands'    => $brands,
            'customers' => $customers,
            'items'     => $items
        ];
        return view('admin.quotation.add', $data);
    }

    public function store(Request $request)
    {
        $request->validate([
            'currency'       =>'required',
            'customer_id'    => 'required',
            'inquiry_id'     => 'sometimes|required',
            'project_name'   => 'required',
            'date'           => 'required',
            'discount'       => 'sometimes',
            'terms_condition'=> 'sometimes',
            'item_id'        => 'required|array',
            'item_id.*'      => 'required',
            'brand_id'       => 'required|array',
            'brand_id.*'     => 'required',
            'quantity'       => 'required|array',
            'quantity.*'     => 'required',
            'unit'           => 'required|array',
            'unit.*'         => 'required',
            'rate'           => 'required|array',
            'rate.*'         => 'required',
            'amount'         => 'required|array',
            'amount.*'       => 'required',
            'total'          => 'required'
        ],[
            'customer_id.required'     => 'The customer field is required.',
            'project_name.required'    => 'The project name field is required.',
            'terms_condition.required' => 'The terms and condition field is required.'
        ]);

        $items = $request->item_id;
        $brands = $request->brand_id;
        $quantities = $request->quantity;
        $units = $request->unit;
        $rates = $request->rate;
        $amounts = $request->amount;

        $data = $request->all();
        $id=Auth::user()->id;
        $data['user_id']  = $id;
        $data['date'] = Carbon::parse($request->date)->format('Y-m-d');
        $data['quotation'] = Uuid::uuid4()->getHex();
        $quotation = new Quotation($data);
        $quotation->save();

        $save = [];

        foreach($items as $index => $item) {
            $item_detail = Item::where('item_name',$item)->where('brand_id', $brands[$index])->first();
            $quotation_item = [
                'quotation_id' => $quotation->id,
                'item_id'  => $item_detail->id,
                'brand_id' => $brands[$index],
                'quantity' => $quantities[$index],
                'unit'     => $units[$index],
                'rate'     => $rates[$index],
                'amount'   => $amounts[$index]
            ];
            $save[] = (new QuotationItem($quotation_item))->save();
        }

        return redirect(
            route('customerquotation.list.admin')
        )->with('success', 'Quotation was added successfully!');
    }

    public function edit($id)
    {
        $customers = Customer::orderBy('id','DESC')->get();
        $brands    = Brand::orderBy('id','DESC')->get();
        $items     = Item::select([
            DB::raw("DISTINCT item_name,id"),
        ])->orderBy('id','DESC')->get();

        $select = [
            "quotations.*",
           # "quotation_item.*",
            "customers.*"
        ];
        $quotation = Quotation::select($select)
            ->join('customers','customers.id','=','quotations.customer_id')
            #->join('quotation_item','quotation_item.quotation_id','=','quotations.id')
            ->where('quotations.id', $id)
            ->first();

        # If quotation was not found
        if (!$quotation) return redirect()->back()->with('error', 'Quotation not found');

        $select = [
            "quotation_item.*",
            "items.item_name"
        ];

        $quotation->items = QuotationItem::select($select)
            ->join('items', 'items.id', '=', 'quotation_item.item_id')
            ->where('quotation_id', $id)
            ->get();

        $data = [
            'title'     => 'Edit Quotation',
            'base_url'  => env('APP_URL', 'http://omnibiz.local'),
            'user'      => Auth::user(),
            'quotation' => $quotation,
            'brands'    => $brands,
            'customers' => $customers,
            'items'     => $items
        ];

        return view('admin.quotation.edit', $data);
    }

    public function update(Request $request,$id)
    {
        $quotation = Quotation::find($id);

        if(!$quotation)
        {
            return redirect(
                route('customerquotation.list.admin')
            )->with('error', 'Quotation doesn\'t exists!');
        }

        $request->validate([
            'currency'       =>'required',
            'customer_id'    => 'required',
            'inquiry_id'     => 'sometimes|required',
            'project_name'   => 'required',
            'date'           => 'required',
            'discount'       => 'sometimes',
            'terms_condition'=> 'sometimes',
            'item_id'        => 'required|array',
            'item_id.*'      => 'required',
            'brand_id'       => 'required|array',
            'brand_id.*'     => 'required',
            'quantity'       => 'required|array',
            'quantity.*'     => 'required',
            'unit'           => 'required|array',
            'unit.*'         => 'required',
            'rate'           => 'required|array',
            'rate.*'         => 'required',
            'amount'         => 'required|array',
            'amount.*'       => 'required',
            'total'          => 'required'
        ],[
            'customer_id.required'     => 'The customer field is required.',
            'project_name.required'    => 'The project name field is required.',
            'terms_condition.required' => 'The terms and condition field is required.'
        ]);

        $quotation->customer_id = $request->customer_id;
        $quotation->project_name = $request->project_name;
        $quotation->date = $request->date;
        $quotation->currency = $request->currency;
        $quotation->discount = $request->discount;
        $quotation->terms_condition = $request->terms_condition;
        $quotation->total = $request->total;
        $quotation->save();

        $quotation_item = QuotationItem::where('quotation_id',$quotation->id)->delete();

        $items = $request->item_id;
        $brands = $request->brand_id;
        $quantities = $request->quantity;
        $units = $request->unit;
        $rates = $request->rate;
        $amounts = $request->amount;

        $save = [];

        foreach($items as $index => $item) {
            $item_detail = Item::where('item_name',$item)->where('brand_id', $brands[$index])->first();
            $quotation_item = [
                'quotation_id' => $quotation->id,
                'item_id'  => $item_detail->id,
                'brand_id' => $brands[$index],
                'quantity' => $quantities[$index],
                'unit'     => $units[$index],
                'rate'     => $rates[$index],
                'amount'   => $amounts[$index]
            ];
            $save[] = (new QuotationItem($quotation_item))->save();
        }

        return redirect(
            route('customerquotation.list.admin')
        )->with('success', 'Quotation was added successfully!');
    }

    public function delete($id)
    {
        $items = QuotationItem::where('quotation_id',$id)->delete();
        $quotation = Quotation::find($id)->delete();
        return redirect(
            route('customerquotation.list.admin')
        )->with('success', 'Customer Quotation deleted successfully!');
    }

    public function view ($id)
    {
        $select=[
            'quotations.*',
            'quotations.created_at as creationdate',
            'quotations.id as unique',
            'items.*',
            'brands.*',
            'customers.*',
            'quotation_item.*',
        ];
        $quotation = Quotation::select($select)
            ->where('quotations.id',$id)
            ->leftJoin('quotation_item', 'quotation_item.quotation_id', '=', 'quotations.id')
            ->leftJoin('brands', 'brands.id', '=', 'quotation_item.brand_id')
            ->leftJoin('items', 'items.id', '=', 'quotation_item.item_id')
            ->leftJoin('customers', 'customers.id', '=', 'quotations.customer_id')
            ->get();
        $data = [
            'title'      => 'Quotations',
            'base_url'   => env('APP_URL', 'http://omnibiz.local'),
            'user'       => Auth::user(),
            'quotation'  => $quotation
        ];
        return view('admin.quotation.item', $data);
    }

    public function generateQuotation($inquiry_id)
    {
        $customers = Customer::orderBy('id','DESC')->get();
        $brands    = Brand::orderBy('id','DESC')->get();
        $items     = Item::select([
            DB::raw("DISTINCT item_name"),
        ])->orderBy('id','DESC')->get();

        $inquiry = Inquiry::select('*')
            ->join('customers','customers.id','=','inquiries.customer_id')
            ->join('inquiry_order','inquiry_order.inquiry_id','=','inquiries.id')
            ->where('inquiries.id', $inquiry_id)
            ->first();

        # If inquiry was not found
        if (!$inquiry) return redirect()->back()->with('error', 'Inquiry not found');

        $select = [
            "inquiry_order.*",
            "items.item_name"
        ];

        $inquiry->items = InquiryOrder::select()
            ->join('items', 'items.id', '=', 'inquiry_order.item_id')
            ->where('inquiry_id', $inquiry_id)
            ->get();

        $data = [
            'title'     => 'Generate Quotation',
            'base_url'  => env('APP_URL', 'http://omnibiz.local'),
            'user'      => Auth::user(),
            'inquiry'   => $inquiry,
            'brands'    => $brands,
            'customers' => $customers,
            'items'     => $items
        ];

        return view('admin.quotation.generatefrominquiry', $data);
    }

    public function pdfinquiry($id)
    {
        $select=[
            'quotations.*',
            'quotations.created_at as creationdate',
            'quotations.id as unique',
            'items.*',
            'brands.*',
            'customers.*',
            'quotation_item.*',
        ];
        $quotation = Quotation::select($select)
            ->where('quotations.id',$id)
            ->leftJoin('quotation_item', 'quotation_item.quotation_id', '=', 'quotations.id')
            ->leftJoin('brands', 'brands.id', '=', 'quotation_item.brand_id')
            ->leftJoin('items', 'items.id', '=', 'quotation_item.item_id')
            ->leftJoin('customers', 'customers.id', '=', 'quotations.customer_id')
            ->get();
        $quotation->creation = \Illuminate\Support\Carbon::createFromTimeStamp(strtotime($quotation[0]->creationdate))->format('d-M-Y');

        $data = [
            'title'      => 'Quotation Pdf',
            'base_url'   => env('APP_URL', 'http://omnibiz.local'),
            'user'       => Auth::user(),
            'quotation'=> $quotation
        ];
        $date = "Quotation-Invoice-". Carbon::now()->format('d-M-Y')  .".pdf";
        $pdf = PDF::loadView('admin.quotation.pdf-invoice', $data);
        return $pdf->download($date);
    }
}
