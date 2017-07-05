<?php

namespace App\Http\Services;

use App\LocationInfo;
use App\LineLog;
use DB;

class LineService
{
    private $httpClient;
    private $bot;

    public function __construct()
    {
        $this->httpClient = new \LINE\LINEBot\HTTPClient\CurlHTTPClient(env('LINE_ACCESS_TOKEN'));
        $this->bot = new \LINE\LINEBot($this->httpClient, ['channelSecret' => env('LINE_SECRET')]);
    }

    /**
     * 驗證是不是從Line那邊傳送過來的訊息
     * @return json資料
     */
    public function validateSignature(){
        $signature = $_SERVER["HTTP_" . \LINE\LINEBot\Constant\HTTPHeader::LINE_SIGNATURE];
        $body = file_get_contents("php://input");
        $events = $this->bot->validateSignature($body, $signature);
        $linelogModel = new LineLog();
        $linelogModel->log = $body;
        $linelogModel->save();
        $body = json_decode($body, true);
        return $body;
    }

    /**
     * 傳送文字
     * @param $replyToken 回覆Token
     * @param $msg_text 訊息內容
     */
    public function sendTextMsg($replyToken, $msg_text)
    {
        $msg = new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($msg_text);
        $this->bot->replyMessage($replyToken, $msg);
    }

    /**
     * 傳送位置
     * @param $replyToken 回覆Token
     * @param $address 地址
     * @param $name 店名
     * @param $lat 經度
     * @param $lng 緯度
     */
    public function sendLocationMsg($replyToken, $address, $name, $lat, $lng)
    {
        $msg = new \LINE\LINEBot\MessageBuilder\LocationMessageBuilder($address, $name, $lat, $lng);
        $this->bot->replyMessage($replyToken, $msg);
    }

    /**
     * 增加店家
     * @param $replyToken 回覆Token
     * @param $message_text_arr 店家資訊，範例（動作;地址;店名;電話;營業時間;經度;緯度;）
     */
    public function addShop($replyToken, $message_text_arr)
    {
        //判斷新增店家的格式是否正確
        if (count($message_text_arr) != 7) {
            $this->sendTextMsg($replyToken, '請輸入店家資訊，格式為『$新增店家;地址;店名;電話;營業時間;lat;lng』');
            exit;
        } else {
            $address = $message_text_arr[1];
            $name = $message_text_arr[2];
            $lat = $message_text_arr[5];
            if (empty($lat)) {
                $this->sendTextMsg($replyToken, '請輸入lat');
                exit;
            }
            $lng = $message_text_arr[6];
            if (empty($lng)) {
                $this->sendTextMsg($replyToken, '請輸入lng');
                exit;
            }
            $telephone = $message_text_arr[3];
            if (count(explode('~', $message_text_arr[4])) != 2) {
                $this->sendTextMsg($replyToken, '請輸入正確的時間格式，格式為『00:00 ~ 23:59』');
                exit;
            }
            $start_time = trim(explode('~', $message_text_arr[4])[0]);
            $end_time = trim(explode('~', $message_text_arr[4])[1]);

            $saveModel = new LocationInfo();
            $saveModel->address = $address;
            $saveModel->name = $name;
            $saveModel->lat = $lat;
            $saveModel->lng = $lng;
            $saveModel->telphone = $telephone;
            $saveModel->start_time = $start_time;
            $saveModel->end_time = $end_time;
            $saveModel->save();

            $msg = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();

            $_msg = new \LINE\LINEBot\MessageBuilder\TextMessageBuilder("這是你要儲存的資訊：");
            $msg->add($_msg);

            $_msg = new \LINE\LINEBot\MessageBuilder\LocationMessageBuilder($address, $name, $lat, $lng);
            $msg->add($_msg);

            $_msg = new \LINE\LINEBot\MessageBuilder\TextMessageBuilder("{$name}，營業時間：{$start_time} ～ {$end_time}，聯絡電話：{$telephone}");
            $msg->add($_msg);

            $actions = array(
                new \LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder("是", "addshop,Y,{$saveModel->id}"),
                new \LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder("否", "addshop,N,{$saveModel->id}")
            );

            $button = new \LINE\LINEBot\MessageBuilder\TemplateBuilder\ConfirmTemplateBuilder("是否要儲存？", $actions);
            $_msg = new \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder("這訊息要用手機的賴才看的到哦", $button);

            $msg->add($_msg);

            $this->bot->replyMessage($replyToken, $msg);
        }
    }

    /**
     * 儲存店家
     * @param $replyToken 回覆Token
     * @param $data 資料，範例 [動作，是否，資料ID]
     */
    public function saveShop($replyToken, $data)
    {
        $isSave = trim(explode(',', $data)[1]) == 'Y' ? true : false;
        if ($isSave) {
            $saveModel = LocationInfo::find(trim(explode(',', $data)[2]));
            if (empty($saveModel)) {
                $this->sendTextMsg($replyToken, '輸入資料有誤，請重新新增店家！');
                echo '輸入資料有誤，請重新新增店家！';
                exit;
            }
            $saveModel->status = 'A';
            $saveModel->save();
            $this->sendTextMsg($replyToken, '資料儲存成功嚕！');
            echo '資料儲存成功嚕！';
            exit;
        } else {
            $saveModel = LocationInfo::find(trim(explode(',', $data)[2]));
            if (empty($saveModel)) {
                $this->sendTextMsg($replyToken, '輸入資料有誤，請重新新增店家！');
                echo '輸入資料有誤，請重新新增店家！';
                exit;
            }
            $saveModel->delete();
            $this->sendTextMsg($replyToken, '好喔，我不幫你儲存惹！');
            echo '好喔，我不幫你儲存惹！';
            exit;
        }
    }

    private $total_distance = 0.5;

    /**
     * 找附近的店家，如果找超過五公里就是邊緣人
     * @param $replyToken 回覆Token
     * @param $lng 經度
     * @param $lat 緯度
     * @param float $distance 範圍（公里），預設3公里
     */
    public function findShop($replyToken, $lng, $lat, $distance = 3)
    {
        $this->total_distance = $distance;
        $squares = $this->returnSquarePoint($lng, $lat,$distance);

        $returnData = DB::table(DB::raw('location_info as t1'))
            ->select('*')
            ->where("t1.lat","<>","0")
            ->where("t1.lat",">","{$squares['right-bottom']['lat']}")
            ->where("t1.lat","<","{$squares['left-top']['lat']}")
            ->where("t1.lng",">","{$squares['left-top']['lng']}")
            ->where("t1.lng","<","{$squares['right-bottom']['lng']}")
            ->whereRaw('? between t1.start_time and t1.end_time', [date("H:i")])
            ->where("t1.status" , "A")
            //->join(DB::raw('(SELECT ROUND(RAND() * (SELECT MAX(id) FROM `location_info`)) AS id) as t2'),'t1.id','>=','t2.id')
            ->orderBy(DB::raw('rand()'))
            ->first();

        if($this->total_distance == 5){
            $msg = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
            $_msg = new \LINE\LINEBot\MessageBuilder\TextMessageBuilder("好可憐喔，你邊緣到附近沒有美食可以吃！");
            $msg->add($_msg);
            $_msg = new \LINE\LINEBot\MessageBuilder\TextMessageBuilder("請管理員多加一點店家讓我可以推薦你吧！");
            $msg->add($_msg);
            $this->bot->replyMessage($replyToken, $msg);
            exit;
        }

        if(empty($returnData)){
            $this->findShop($replyToken, $lng, $lat, $this->total_distance + 1);
            exit;
        }

        $msg = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
        $_msg = new \LINE\LINEBot\MessageBuilder\TextMessageBuilder("今天吃這家店吧！");
        $msg->add($_msg);
        $_msg = new \LINE\LINEBot\MessageBuilder\LocationMessageBuilder(
            $returnData->address,
            $returnData->name,
            $returnData->lat,
            $returnData->lng
        );
        $msg->add($_msg);
        $_msg = new \LINE\LINEBot\MessageBuilder\TextMessageBuilder("{$returnData->name}，營業時間：{$returnData->start_time} ～ {$returnData->end_time}，聯絡電話：{$returnData->telphone}");
        $msg->add($_msg);
        $this->bot->replyMessage($replyToken, $msg);

    }

    /**
     *計算某個經緯度的周圍某段距離的正方形的四個點
     *@param lng float 經度
     *@param lat float 緯度
     *@param distance float 該點所在圓的半徑，該圓與此正方形內切，默認值為500公尺
     *@return array 正方形的四個點的經緯度坐標
     */
    private function returnSquarePoint($lng, $lat, $distance = 0.5)
    {
        $EARTH_RADIUS = 6371;
        $dlng = 2 * asin(sin($distance / (2 * $EARTH_RADIUS)) / cos(deg2rad($lat)));
        $dlng = rad2deg($dlng);
        $dlat = $distance / $EARTH_RADIUS;
        $dlat = rad2deg($dlat);
        return array('left-top' => array('lat' => $lat + $dlat, 'lng' => $lng - $dlng), 'right-top' => array('lat' => $lat + $dlat, 'lng' => $lng + $dlng), 'left-bottom' => array('lat' => $lat - $dlat, 'lng' => $lng - $dlng), 'right-bottom' => array('lat' => $lat - $dlat, 'lng' => $lng + $dlng));
    }
}