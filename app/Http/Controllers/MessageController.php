<?php

namespace App\Http\Controllers;

use App\Events\Webhook;
use App\Libraries\WhatsApp;
use Exception;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        try {
            $messages = DB::table('messages', 'm')
            ->whereRaw('m.id IN (SELECT MAX(id) FROM messages m2 GROUP BY wa_id)')
            ->orderByDesc('m.id')
            ->get();

            return response()->json([
                'success' => true,
                'data' => $messages,
            ],200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ],500);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        try {

            $request->validate([
                'wa_id' => ['required', 'max:20'],
                'body' => ['required', 'string']
            ]);

            $input = $request->all();
            $wp = new WhatsApp();
            $respone = $wp->sendText($input['wa_id'], $input['body']);

            $message = new Message();
            $message->wa_id = $input['wa_id'];
            $message->wam_id = $respone['messages'][0]['id'];
            $message->type = 'text';
            $message->outgoing = true;
            $message->body = $input['body'];
            $message->status = 'sent';
            $message->caption = '';
            $message->data = '';
            $message->save();

            return response()->json([
                'success' => true,
                'data' => $message,
            ],200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ],500);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Message  $message
     * @return \Illuminate\Http\Response
     */
    public function show($waId, Request $request)
    {
        try {
            $messages = DB::table('messages', 'm')
            ->where('wa_id', $waId)
            ->orderBy('created_at')
            ->get();

            return response()->json([
                'success' => true,
                'data' => $messages,
            ],200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ],500);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Message  $message
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Message $message)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Message  $message
     * @return \Illuminate\Http\Response
     */
    public function destroy(Message $message)
    {
        //
    }

    public function sendMessage()
    {

        try {
            // $token = env('WHATSAPP_API_TOKEN');
            // $phoneId = env('WHATSAPP_API_PHONE_ID');
            // $version = "v15.0";
            // $payload = [
            //     'messaging_product' => 'whatsapp',
            //     'to' => '573017639581',
            //     'type' => 'template',
            //     "template" => [
            //         "name" => "hello_world",
            //         "language" => [
            //             "code" => "en_US"
            //         ]
            //     ]
            // ];
            
            // $message = Http::withToken($token)->post('https://graph.facebook.com/'.$version.'/'.$phoneId.'/messages', $payload)->throw()->json();

            $wp = new WhatsApp();
            $message = $wp->sendText('573017639581', 'Hola');

            return response()->json([
                'success' => true,
                'data' => $message,
            ],200);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ],500);
        }

    }

    public function verifyWebhook(Request $request)
    {
        try {
            $query = $request->query();

            $mode = $query['hub_mode'];
            $token = $query['hub_verify_token'];
            $challenge = $query['hub_challenge'];

            if ($mode && $token) {
                if ($mode == 'subscribe' && $token == env('VERIFY_TOKEN')) {
                    return response($challenge, 200)->header('Content-Type', 'text/plain');
                }
            }

            throw new Exception('Invalid request');
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ],500);
        }
    }

    public function processWebhook(Request $request)
    {
        try {
            $bodyContent = json_decode($request->getContent(), true);

            $value = $bodyContent['entry'][0]['changes'][0]['value'];

            if (!empty($value['statuses'])) {
                $status = $value['statuses'][0]['status'];
                $wam = Message::where('wam_id', $value['statuses'][0]['id'])->first();

                if (!empty($wam->id)) {
                    $wam->status = $status;
                    $wam->save();
                    Webhook::dispatch($wam, true);
                }
            }else if (!empty($value['messages'])) {
                $exists = Message::where('wam_id', $value['messages'][0]['id'])->first();

                if (empty($exists->id)) {
                    $mediaSupported = ['audio', 'document', 'image', 'video', 'sticker'];

                    if ($value['messages'][0]['type'] == 'text') {
                        $message = $this->_saveMessage(
                            $value['messages'][0]['text']['body'],'text',
                            $value['messages'][0]['from'],
                            $value['messages'][0]['id'],
                            $value['messages'][0]['timestamp']
                        );

                        Webhook::dispatch($message, false);

                    }elseif (in_array($value['messages'][0]['type'], $mediaSupported)) {
                        
                        $mediaType = $value['messages'][0]['type'];
                        $mediaId = $value['messages'][0][$mediaType]['id'];
                        $wp = new WhatsApp();
                        $file = $wp->downloadMedia($mediaId);

                        $caption = null;
                        if (!empty($value['messages'][0][$mediaType]['caption'])) {
                            $caption = $value['messages'][0][$mediaType]['caption'];
                        }

                        if (!is_null($file)) {
                            $message = $this->_saveMessage(
                                // asset('/storage/'.$file), //Asi guarda con la url de ngrok
                                'http://localhost:8000/storage/'.$file,
                                $mediaType,
                                $value['messages'][0]['from'],
                                $value['messages'][0]['id'],
                                $value['messages'][0]['timestamp'],
                                $caption
                            );
                        }

                    } else{
                        $type = $value['messages'][0]['type'];
                        if (!empty($value['messages'][0][$type])) {
                            $message = $this->_saveMessage(
                                "($type): \n _".serialize($value['messages'][0][$type]) . "_",'other',
                                $value['messages'][0]['from'],
                                $value['messages'][0]['id'],
                                $value['messages'][0]['timestamp']
                            );

                            Webhook::dispatch($message, false);
                        }
                    }   
                }
            }

            return response()->json([
                'success' => true,
            ],200);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ],500);
        }
    }

    private function _saveMessage($message, $messageType, $waId, $wamId, $timestamp = null, $caption = null, $data = ''){
        $wam = new Message();
        $wam->body = $message;
        $wam->outgoing = false;
        $wam->type = $messageType;
        $wam->wa_id = $waId;
        $wam->wam_id = $wamId;
        $wam->status = 'sent';
        $wam->caption = $caption;
        $wam->data = $data;

        if (!is_null($timestamp)) {
            $wam->created_at = Carbon::createFromTimestamp($timestamp)->toDateTimeString();
            $wam->updated_at = Carbon::createFromTimestamp($timestamp)->toDateTimeString();
        }

        $wam->save();

        return $wam;
    }
}
