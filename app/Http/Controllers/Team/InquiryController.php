<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\InquiryOrder;
use App\Models\Item;
use App\Models\User;
use App\Models\UserCategory;
use Barryvdh\DomPDF\Facade as PDF;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

class InquiryController extends Controller
{
    public function index()
    {
        $select = [
            'customers.customer_name',
            'inquiries.id',
            'inquiries.project_name',
            'inquiries.currency',
            'inquiries.total',
            'inquiries.date',
            'inquiries.timeline',
            'users.name',
            DB::raw("GROUP_CONCAT(categories.category_name) as category_names"),
            DB::raw("(
                CASE
                    WHEN `quotations`.`id` iS NULL
                        THEN 'open'
                    ELSE 'close'
                END
            ) as 'inquiry_status'"),
            DB::raw("COUNT(inquiry_order.id) as item_count"),
            DB::raw("SUM(inquiry_order.amount) as amount")
        ];
        $inquiries = Inquiry::select($select)
            ->leftJoin('customers','customers.id','=','inquiries.customer_id')
            ->leftJoin('inquiry_documents','inquiry_documents.inquiry_id','=','inquiries.id')
            ->leftJoin('inquiry_order','inquiry_order.inquiry_id', '=', 'inquiries.id')
            ->leftJoin('categories', 'categories.id' ,'=', 'inquiry_order.category_id')
            ->leftJoin('users', 'users.id' ,'=', 'inquiries.user_id')
            ->leftJoin('quotations', 'quotations.inquiry_id' ,'=', 'inquiries.id')
            # The line below is a where clause which will only fetch the records for the specified category_id
            # In our case, we get the category_id from the userCategory table which is linked to the user_id
            ->whereIn('categories.id', UserCategory::select('category_id as id')->where('user_id', Auth::user()->id)->get())
            # where IN (value1, value2, value3)
            # where IN ($array_of_objects)
            #->where('user_id', Auth::id()->get())
            ->groupBy('inquiries.id','inquiry_order.inquiry_id')
            ->paginate($this->count);

        $data = [
            'title'   => 'View Inquires',
            'user'    => Auth::user(),
            'inquiries' => $inquiries
        ];
        return view('team.inquiry.view', $data);
    }

    public function open(Request $request)
    {
        $select = [
            'customers.customer_name',
            'inquiries.id',
            'inquiries.project_name',
            'inquiries.currency',
            'inquiries.total',
            'inquiries.date',
            'inquiries.timeline',
            'users.name',
            DB::raw("GROUP_CONCAT(categories.category_name) as category_names"),
            DB::raw("(
                CASE
                    WHEN `quotations`.`id` iS NULL
                        THEN 'open'
                    ELSE 'close'
                END
            ) as 'inquiry_status'"),
            DB::raw("COUNT(inquiry_order.id) as item_count"),
            DB::raw("SUM(inquiry_order.amount) as amount")
        ];
        $inquiries = Inquiry::select($select)
            ->leftJoin('customers','customers.id','=','inquiries.customer_id')
            ->leftJoin('inquiry_documents','inquiry_documents.inquiry_id','=','inquiries.id')
            ->leftJoin('inquiry_order','inquiry_order.inquiry_id', '=', 'inquiries.id')
            ->leftJoin('categories', 'categories.id' ,'=', 'inquiry_order.category_id')
            ->leftJoin('users', 'users.id' ,'=', 'inquiries.user_id')
            ->leftJoin('quotations', 'quotations.inquiry_id' ,'=', 'inquiries.id')
            ->whereNull('quotations.id')
            # The line below is a where clause which will only fetch the records for the specified category_id
            # In our case, we get the category_id from the userCategory table which is linked to the user_id
            ->whereIn('categories.id', UserCategory::select('category_id as id')->where('user_id', Auth::id()))
            #->where('user_id', Auth::id()->get())
            ->groupBy('inquiries.id','inquiry_order.inquiry_id');

        # Applying filters
        # 1. Applying sales person filter
        $request->sales_person && $inquiries = $inquiries->where('users.id', $request->sales_person);
        # 2. Applying customer name filter
        $request->customer_id && $inquiries = $inquiries->where('customers.id', $request->customer_id);
        # 3. Applying project name filter
        $request->project && $inquiries = $inquiries->where('quotations.project_name', 'LIKE', "%$request->project%");
        # 4. Applying start date and end date filter
        $start_date = $request->from;
        $end_date = $request->to;
        $request->from && $inquiries = $inquiries->where('quotations.created_at', '>', $start_date);
        $request->to && $inquiries = $inquiries->where('quotations.created_at', '<', $end_date);

        $inquiries = $inquiries->paginate($this->count);
        $data = [
            'title'   => 'Open Inquires',
            'user'    => Auth::user(),
            'inquiries' => $inquiries,
            'sales_people' => User::where('user_role','sale')->get(),
            'customers' => Customer::all(),
            'request' => $request,
            'reset_url' => route('inquiry.open.team')
        ];
        return view('team.inquiry.open',$data);
    }

    public function add()
    {
        $customers  = Customer::orderBy('id','DESC')->get();
        $categories = Category::orderBy('id','DESC')->whereIn('categories.id', UserCategory::select('category_id as id')->where('user_id', Auth::user()->id)->get())->get();
        $brands     = Brand::orderBy('id','DESC')->get();
        $items      = Item::orderBy('id','DESC')->get();

        $data = [
            'title'      => 'Submit Inquiry',
            'base_url'   => env('APP_URL', 'http://127.0.0.1:8000'),
            'user'       => Auth::user(),
            'brands'     => $brands,
            'categories' => $categories,
            'customers'  => $customers,
            'items'      => $items
        ];
        return view('team.inquiry.add', $data);
    }

    public function store(Request $request)
    {
        $request->validate([
            'customer_id'    => 'required',
            'project_name'   => 'required',
            'date'           => 'required',
            'timeline'       => 'required',
            'total'          => 'required',
            'remarks'        => 'sometimes',
            'rate'           => 'required|array',
            'rate.*'         => 'required',
            'category_id'    => 'required|array',
            'category_id.*'  => 'required',
            'item_id'        => 'required|array',
            'item_id.*'      => 'required',
            'brand_id'       => 'required|array',
            'brand_id.*'     => 'required',
            'quantity'       => 'required|array',
            'quantity.*'     => 'required',
            'unit'           => 'required|array',
            'unit.*'         => 'required',
            'amount'         => 'required|array',
            'amount.*'       => 'required',
            'inquiry_file'   => 'required|array',
            'inquiry_file.*' => 'required|',
        ],[
            'customer_id.required'     => 'The customer field is required.',
            'project_name.required'    => 'The project name field is required.'
        ]);

        $files      = $request->inquiry_file;
        $items      = $request->item_id;
        $categories = $request->category_id;
        $brands     = $request->brand_id;
        $quantities = $request->quantity;
        $units      = $request->unit;
        $rates      = $request->rate;
        $amounts    = $request->amount;

        $data = $request->all();
        $id=Auth::user()->id;
        $data['user_id']  = $id;
        $data['date']     = Carbon::parse($request->date)->format('Y-m-d');
        $data['timeline'] = Carbon::parse($request->timeline)->format('Y-m-d');
        $data['inquiry']  = Uuid::uuid4()->getHex();
        $inquiry = new Inquiry($data);
        $inquiry->save();

        $save = [];
        $save_document = [];

        foreach($files as $file) {
            $file_item = [
                'inquiry_id'   => $inquiry->id,
                'file_path'    => $this->uploadPDF($file)
            ];
            $save_document[] = (new InquiryDocument($file_item))->save();
        }

        foreach($categories as $index => $category) {
            $item_detail = Item::select('*')
                ->where('item_name',$items[$index])
                ->where('brand_id', $brands[$index])
                ->first();

            $inquiry_item = [
                'inquiry_id'   => $inquiry->id,
                'category_id'  => $category,
                'item_id'      => $item_detail->id,
                'brand_id'     => $brands[$index],
                'quantity'     => $quantities[$index],
                'unit'         => $units[$index],
                'rate'         => $rates[$index],
                'amount'       => $amounts[$index],
            ];
            $save[] = (new InquiryOrder($inquiry_item))->save();
        }
        return redirect(
            route('inquiry.list.team')
        )->with('success', 'Inquiry was added successfully!');
    }

    private function uploadPDF($file)
    {
        $filename  = Uuid::uuid4().".{$file->extension()}";
        $private_path = $file->storeAs('public/inquiry',$filename);
        $public_path  = Storage::url("inquiry/$filename");
        return $filename;
    }

    public function edit($id)
    {
        $customers = Customer::orderBy('id','DESC')->get();
        $brands    = Brand::orderBy('id','DESC')->get();
        $categories    = Category::orderBy('id','DESC')->get();
        $items     = Item::select([
            DB::raw("DISTINCT item_name,id"),
        ])->orderBy('id','DESC')->get();

        $select = [
            "customers.*",
            #   "inquiry_order.*",
            "inquiries.*",
        ];

        $inquiry = Inquiry::select($select)
            ->join('customers','customers.id','=','inquiries.customer_id')
            # ->join('inquiry_order','inquiry_order.inquiry_id','=','inquiries.id')
            ->where('inquiries.id', $id)
            ->first();

        # If inquiry was not found
        if (!$inquiry) return redirect()->back()->with('error', 'Inquiry not found');

        $select_item = [
            "inquiry_order.*",
            "items.item_name"
        ];

        $inquiry->items = InquiryOrder::select($select_item)
            ->join('items', 'items.id', '=', 'inquiry_order.item_id')
            ->whereIn('inquiry_order.category_id', UserCategory::select('category_id as id')->where('user_id', Auth::user()->id)->get())
            ->where('inquiry_order.inquiry_id', $id)
            ->get();

        $data = [
            'title'     => 'Edit Inquiry',
            'base_url'  => env('APP_URL', 'http://omnibiz.local'),
            'user'      => Auth::user(),
            'inquiry'   => $inquiry,
            'brands'    => $brands,
            'customers' => $customers,
            'items'     => $items,
            'categories'=>$categories
        ];

        return view('team.inquiry.edit', $data);
    }

    public function view($id)
    {
        $select=[
            'inquiries.*',
            'inquiries.id as unique',
            'items.*',
            'categories.*',
            'brands.*',
            'inquiry_order.*',
            'customers.*',
        ];
        $inquires = Inquiry::select($select)
            ->where('inquiries.id',$id)
            ->leftJoin('customers','customers.id','=','inquiries.customer_id')
            ->leftJoin('inquiry_documents','inquiry_documents.inquiry_id','=','inquiries.id')
            ->leftJoin('inquiry_order','inquiry_order.inquiry_id', '=', 'inquiries.id')
            ->leftJoin('brands','brands.id' ,'=', 'inquiry_order.brand_id')
            ->leftJoin('categories', 'categories.id' ,'=', 'inquiry_order.category_id')
            ->leftJoin('users', 'users.id' ,'=', 'inquiries.user_id')
            ->leftJoin( 'items','items.id' ,'=', 'inquiry_order.item_id')
            ->whereIn('inquiry_order.category_id', UserCategory::select('category_id as id')->where('user_id', Auth::user()->id)->get())
            ->groupBy('inquiries.id','inquiry_order.inquiry_id')
            ->get();


        $data = [
            'title'   => 'View Inquires',
            'user'    => Auth::user(),
            'inquiry'=> $inquires
        ];
        return view('team.inquiry.item',$data);
    }

    public function ajaxFetchCategory(Request $request)
    {
        $request->validate([
            'category' => 'required'
        ]);
        $category_id = $request->category;
        $category_items = Item::select([DB::raw('DISTINCT item_name')])
            ->whereIn('category_id', UserCategory::select('category_id as id')->where('user_id', Auth::user()->id)->get())
            ->get();
        return response($category_items, 200);
    }

    public function ajaxFetchItem(Request $request)
    {
        $request->validate([
            'item' => 'required'
        ]);
        $item_name = $request->item;
        $item_categories = Item::select(['brands.brand_name', 'brands.id'])
            ->join('brands', 'brands.id', '=', 'items.brand_id')
            ->where('items.item_name','like',"%{$item_name}%")
            ->groupBy('brands.id')
            ->get();
        return response($item_categories, 200);
    }

    public function pdfinquiry($id)
    {
        $select=[
            'inquiries.*',
            'inquiries.created_at as creationdate',
            'inquiries.id as unique',
            'items.*',
            'categories.*',
            'brands.*',
            'inquiry_order.*',
            'customers.*',
        ];
        $inquires = Inquiry::select($select)
            ->where('inquiries.id',$id)
            ->leftJoin('customers','customers.id','=','inquiries.customer_id')
            ->leftJoin('inquiry_documents','inquiry_documents.inquiry_id','=','inquiries.id')
            ->leftJoin('inquiry_order','inquiry_order.inquiry_id', '=', 'inquiries.id')
            ->leftJoin('brands','brands.id' ,'=', 'inquiry_order.brand_id')
            ->leftJoin('categories', 'categories.id' ,'=', 'inquiry_order.category_id')
            ->leftJoin('users', 'users.id' ,'=', 'inquiries.user_id')
            ->whereIn('inquiry_order.category_id', UserCategory::select('category_id as id')->where('user_id', Auth::user()->id)->get())
            ->leftJoin( 'items','items.id' ,'=', 'inquiry_order.item_id')
            ->groupBy('inquiries.id','inquiry_order.inquiry_id')
            ->get();

        $inquires->creation = Carbon::createFromTimeStamp(strtotime($inquires[0]->creationdate))->format('d-M-Y');

        $data = [
            'title'      => 'Inquiry Pdf',
            'base_url'   => env('APP_URL', 'http://omnibiz.local'),
            'user'       => Auth::user(),
            'inquiry'=> $inquires
        ];
        $date = "Inquiry-Invoice-". Carbon::now()->format('d-M-Y')  .".pdf";
        $pdf = PDF::loadView('team.inquiry.pdf-invoice', $data);
        return $pdf->download($date);
    }

    public function update(Request $request,$id)
    {
        $inquiry = Inquiry::find($id);

        if(!$inquiry)
        {
            return redirect(
                route('inquiry.list.team')
            )->with('error', 'Inquiry doesn\'t exists!');
        }

        $request->validate([
            'customer_id'    => 'required',
            'project_name'   => 'required',
            'date'           => 'required',
            'currency'       => 'required',
            'discount'       => 'sometimes',
            'timeline'       => 'required',
            'total'          => 'required',
            'remarks'        => 'sometimes',
            'rate'           => 'required|array',
            'rate.*'         => 'required',
            'item_id'        => 'required|array',
            'item_id.*'      => 'required',
            'brand_id'       => 'required|array',
            'brand_id.*'     => 'required',
            'quantity'       => 'required|array',
            'quantity.*'     => 'required',
            'unit'           => 'required|array',
            'unit.*'         => 'required',
            'amount'         => 'required|array',
            'amount.*'       => 'required',
        ],[
            'customer_id.required'     => 'The customer field is required.',
            'project_name.required'    => 'The project name field is required.'
        ]);

        $inquiry->customer_id = $request->customer_id;
        $inquiry->project_name = $request->project_name;
        $inquiry->date = $request->date;
        $inquiry->currency = $request->currency;
        $inquiry->discount = $request->discount;
        $inquiry->remarks = $request->remarks;
        $inquiry->total = $request->total;
        $inquiry->save();

        $inquiry_item = InquiryOrder::where('inquiry_id',$inquiry->id)->whereIn('category_id', UserCategory::select('category_id as id')->where('user_id', Auth::user()->id)->get())->delete();

        $items      = $request->item_id;
        $categories = $request->category_id;
        $brands     = $request->brand_id;
        $quantities = $request->quantity;
        $units      = $request->unit;
        $rates      = $request->rate;
        $amounts    = $request->amount;

        $save = [];

        foreach($categories as $index => $category) {
            $item_detail = Item::select('*')
                ->where('item_name',$items[$index])
                ->where('brand_id', $brands[$index])
                ->first();
            $inquiry_item = [
                'inquiry_id'   => $inquiry->id,
                'category_id'  => $category,
                'item_id'      => $item_detail->id,
                'brand_id'     => $brands[$index],
                'quantity'     => $quantities[$index],
                'unit'         => $units[$index],
                'rate'         => $rates[$index],
                'amount'       => $amounts[$index],
            ];
            $save[] = (new InquiryOrder($inquiry_item))->save();
        }
        return redirect(
            route('inquiry.list.team')
        )->with('success', 'Inquiry was updated successfully!');
    }

}
