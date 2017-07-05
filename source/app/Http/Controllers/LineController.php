<?php

namespace App\Http\Controllers;

use App\Http\Services\LineService;

use Illuminate\Http\Request;
use DB;

class LineController extends Controller
{

    public function index(Request $request)
    {

        try {

            $service = new LineService();
            $body = $service->validateSignature();
            $replyToken = $body["events"][0]["replyToken"];
            $message_type = $body["events"][0]["type"];

            //message event
            if ($message_type == 'message') {
                $message_type = $body["events"][0]["message"]["type"];

                //文字
                if ($message_type == 'text') {
                    $message_text = $body["events"][0]["message"]["text"];
                    $message_text_arr = explode(';', $message_text);
                    if (count($message_text_arr) > 0) {
                        $message_command = $message_text_arr[0];
                        if ($message_command == '$新增店家') {
                            if($body["events"][0]["source"]["userId"] == 'U771bc7ddcd3f89c0d66fdfbbe1b35596'){
                                $service->addShop($replyToken, $message_text_arr);
                            }
                        }
                    }

                //座標
                } else if($message_type == 'location') {
                    $lat = $body["events"][0]["message"]["latitude"];
                    $lng = $body["events"][0]["message"]["longitude"];
                    $service->findShop($replyToken, $lng, $lat);

                //其他類型
                } else {
                    //$service->sendTextMsg($replyToken, '現在只能接受文字訊息喔！');
                    exit;
                }

            //postback event
            } else if ($message_type == 'postback') {
                $data = $body["events"][0]["postback"]["data"];

                //postback 自訂規範一定是 Action，資料，針對哪個ID
                if (count(explode(',', $data)) != 3) {
                    $service->sendTextMsg($replyToken, '輸入資料有誤，請重新輸入指令！');
                    exit;
                } else {
                    if (trim(explode(',', $data)[0]) == 'addshop') {
                        $service->saveShop($replyToken, $data);
                    }
                }

            //other event
            } else {
                //$service->sendTextMsg($replyToken, '現在只能接受文字訊息喔！');
                exit;
            }
        } catch (\Exception $e) {
            echo "who are you!!!!";
        }
    }

}
