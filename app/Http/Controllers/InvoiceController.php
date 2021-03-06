<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Mail\InvoiceEmail;
use App\Jobs\InvoiceEmailJob;
use App\Models\Task;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class InvoiceController extends Controller
{
    /**
     * function index
     * display invoice
    */
    public function index(Request $request)
    {
        //get latest invoice
        $invoices = Invoice::with('client')->where('user_id',Auth::id())->latest();

        //filter by client
        if(!empty($request->client_id)){
            $invoices = $invoices->where('client_id', $request->client_id);
        }

        //filter by status
        if(!empty($request->status)){
            $invoices = $invoices->where('status', $request->status);
        }

        //filter by email sent
        if(!empty($request->emailsent)){
            $invoices = $invoices->where('email_sent', $request->emailsent);
        }

        //paginate
        $invoices = $invoices->paginate(10);
        //return view with client and invoices
        return view('invoice.index')->with([
            'clients' => Client::where('user_id',Auth::user()->id)->get(),
            'invoices'=> $invoices,
        ]);
    }
     /**
     * function Create
     * @param request
     * Method Get
     * search query
     *
    */
    public function create(Request $request)
    {
        $tasks = false;
        //if client id and status is not empty
        if(!empty($request->client_id) && !empty($request->status)){
            //validation
            $request->validate([
                'client_id' => ['required','not_in:none'],
                'status' => ['required','not_in:none'],
            ]);
            //get tasks from request
             $tasks = $this->getInvoiceData($request);
        }
        //return view with client and task
       return view('invoice.create')->with([
           'clients' =>Client::where('user_id',Auth::user()->id)->get(),
           'tasks' =>$tasks
       ]);
    }
    /**
     * Mthod Update
     * @param request
     * @param Invoice $invoice
     * @param void
     *
    */
    public function update(Invoice $invoice,Request $request)
    {
        try {
            //update status
            $invoice->update([
                'status' => $invoice->status == 'unpaid'? 'paid': 'unpaid'
            ]);
            //return response
            return redirect()->route('invoice.index')->with('success','Invoice Payment mark as paid');
        }catch (\Throwable $th){
            //throw $th
            return redirect()->route('invoice.index')->with('error',$th);
        }

    }
    /**
     * Mthod Destroy
     * @param Invoice $invoice
     * @param void
     *
    */
    public function destroy(Invoice $invoice)
    {
        try {
            //delete pdf file
            Storage::delete('public/invoices/'.$invoice->download_url);
            //delete data fron database
            $invoice->delete();
            //return response
            return redirect()->route('invoice.index')->with('success','Invoice Deleted');
        }catch (\Throwable $th){
            //throw $th
            return redirect()->route('invoice.index')->with('error',$th);
        }

    }
    /**
     * Method getInvoiceData
     *
     * @param Request $request
     *
     * @return void
     */
    public function getInvoiceData(Request $request)
    {
        try {
            //get latest tasks
            $tasks = Task::latest();
            //filter by clients
            if(!empty($request->client_id)){
                $tasks = $tasks->where('client_id', '=' , $request->client_id);
            }

            //filter by status
            if(!empty($request->status)){
                $tasks = $tasks->where('status', '=' , $request->status);
            }

            //filter by from data
            if(!empty($request->fromDate)){
                $tasks = $tasks->whereDate('created_at', '>=' , $request->fromDate);
            }

            //filter by end data
            if(!empty($request->endDate)){
                $tasks = $tasks->whereDate('created_at', '<=' , $request->endDate);
            }

            //return tasks
            return $tasks->get();
        }catch (\Throwable $th){
            //throw false
            return false;
        }


    }
    /**
     * Method preview
     *
     * @param Request $request
     *
     * @return void
     */
    public function preview(Request $request)
    {
    }
    /**
     * Method invoice
     *
     * @param Request $request
     *
     * @return void
     */
    public function invoice(Request $request){
        //if rewuest is generate
        if (!empty($request->generate) && $request->generate == 'yes'){
            try {
                //generate invoice pdf
                $this->generate($request);
                //return response
                return redirect()->route('invoice.index')->with('success','Invoice Created');
            }catch (\Throwable $th){
                //throw $th
                return redirect()->route('invoice.index')->with('error',$th);
            }

        }
        //if request is preview invoice
        if (!empty($request->preview) && $request->preview == 'yes'){
            //check discount and discount type
            if (!empty($request->discount) && !empty($request->discount_type)){
                $discount = $request->discount;
                $discount_type = $request->discount_type;
            }else{
                $discount = 0;
                $discount_type = '';
            }
            //get tasks from request ids
            $tasks = Task::whereIn('id', $request->invoice_ids)->get();
            //return view with invoice
            return view('invoice.preview')->with([
                'invoice_no' => 'INVO_'.rand(255,255555),
                'user' => Auth::user(),
                'tasks' => $tasks,
                'discount' => $discount,
                'discount_type' => $discount_type
            ]);
        }
    }
    /**
     * Method generate
     *
     * @param Request $request
     *
     * @return void
     */
    public function generate(Request $request)
    {

        //check discount and discount type
            if (!empty($request->discount) && !empty($request->discount_type)){
                $discount = $request->discount;
                $discount_type = $request->discount_type;
            }else{
                $discount = 0;
                $discount_type = '';
            }
            // get tasks from request ids
            $tasks = Task::whereIn('id', $request->invoice_ids)->get();

            //generate invoice random id
            $invo_no ='INVO_'.rand(255,255555);
            //get all data into an array
            $data =[
                'invoice_no' =>$invo_no ,
                'user' => Auth::user(),
                'tasks' => $tasks,
                'discount' => $discount,
                'discount_type' => $discount_type
            ];

          //Generation PDF
            $pdf = PDF::loadView('invoice.pdf', $data);
              //storage pdf in storage
            Storage::put('public/invoice/'.$invo_no. '.pdf', $pdf->output());

            //Insert Invoice data
            Invoice::create([
                'invoice_id' => $invo_no,
                'client_id' => $tasks->first()->client->id,
                'user_id'  => Auth::user()->id,
                'status'   => 'unpaid',
                'amount'   => $tasks->sum('price'),
                'download_url' => $invo_no. '.pdf',
            ]);


    }
    public function sendEmail(Invoice $invoice)
    {
        try {
            //get all data into an array
            $data = [
                'user' => Auth::user(),
                'invoice_id' => $invoice->invoice_id,
                'invoice' => $invoice,
                'pdf'   =>public_path('storage/invoice/'.$invoice->download_url),
            ];

            //email initialize with mailable and queue
            Mail::to($invoice->client)->send(new InvoiceEmail($data));
            //update invoice email sent status
            $invoice->update([
                'email_sent' => 'yes'
            ]);
            //return response
            return redirect()->route('invoice.index')->with('success','Email Send');
        }catch (\Throwable $th){
            //throw $th
            return redirect()->route('invoice.index')->with('error',$th);
        }


   }

}



