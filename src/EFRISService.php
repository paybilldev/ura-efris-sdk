<?php

namespace UraEfrisSdk;

use JsonException;
use UraEfrisSdk\Invoicing\CreditNote\CancelNote;
use UraEfrisSdk\Invoicing\CreditNote\CreditNote;
use UraEfrisSdk\Invoicing\CreditNote\CreditNoteQuery;
use UraEfrisSdk\Invoicing\Invoice;
use UraEfrisSdk\Invoicing\InvoiceQuery;
use UraEfrisSdk\Misc\EFRISException;
use UraEfrisSdk\Misc\Enums\TaxpayerType;
use UraEfrisSdk\Misc\TaxpayerInfo;
use UraEfrisSdk\Payload\Data;
use UraEfrisSdk\Payload\GlobalInfo;
use UraEfrisSdk\Payload\GoodsStockTransfer;
use UraEfrisSdk\Payload\Payload;
use UraEfrisSdk\Product\GoodsStockMaintain;
use UraEfrisSdk\Product\ProductQuery;
use UraEfrisSdk\Product\ProductUpload;
use UraEfrisSdk\Product\StockTransfer;
use UraEfrisSdk\Product\StockTransferItem;
use UraEfrisSdk\Response\Invoice\CreditNote\CreditNoteResponse;
use UraEfrisSdk\Response\Invoice\InvoiceResponse;
use UraEfrisSdk\Response\PagedQueryResponse;
use UraEfrisSdk\Response\Response;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class EFRISService
{
    public string $tin;
    public string $deviceNo;
    public ?string $timeZone;

    public function __construct(protected Serializer $serializer)
    {
    }

    /**
     * @param mixed $content
     * @param string $interfaceCode
     * @param $type
     * @param bool $encrypt
     * @return Response|bool
     */
    public function send(mixed $content, string $interfaceCode, string $type, bool $encrypt = true): Response|bool
    {
        $aesKey = null;
        $data = new Data();
        if (!is_null($content)) {
            $data->content = self::json_serialize($content);
        }
        try {

            if ($encrypt) {
                $aesKey = self::getAESKey();
                $data->encrypt($aesKey);
            }
            if (!empty($data->content)) {
                $data->sign();
            }
            return self::post($interfaceCode, $data, $type, $aesKey);
        } catch (EFRISException $e) {
            return $e->data;
        }
    }

    /**
     * @return bool|string
     * @throws EFRISException
     */
    private function getAESKey(): bool|string
    {
        $aesKey = null;
        $data = new Data(content: "");
        return $this->post("T104", $data, "string", $aesKey)->data;
    }

    /**
     * @param string $json
     * @param string $type
     * @return mixed
     */
    private function json_deserialize(string $json, string $type): mixed
    {
        if ($type == "array")
            return $this->serializer->deserialize($json, $type, 'json');
        return $this->serializer->deserialize($json, $type, 'json',
            [AbstractObjectNormalizer::SKIP_NULL_VALUES, AbstractObjectNormalizer::SKIP_UNINITIALIZED_VALUES]);
    }

    private function json_serialize(mixed $data): string
    {
        return $this->serializer->serialize($data, 'json');
    }

    /**
     * @param ProductUpload $productUpload
     * @return Response
     */
    public function configureProduct(ProductUpload $productUpload): Response
    {
        return $this->send($productUpload->products, "T130", "array", true);
    }

    public function queryProduct(string $pageSize = "10", string $pageNo = "1", $goodName = "", $goodsCode = ""): Response
    {
        $query = new ProductQuery(pageNo: $pageNo, pageSize: $pageSize);
        return $this->send($query, "T127", PagedQueryResponse::class, true);
    }

    /**
     * @param GoodsStockMaintain $goodsStockMaintain
     * @return Response
     */
    public function manageStock(GoodsStockMaintain $goodsStockMaintain): Response
    {
        return $this->send($goodsStockMaintain, "T131", "UraEfrisSdk\Product\StockInItem[]");
    }

    /**
     * @param StockTransfer $stockTransfer
     * @param array<StockTransferItem> $stockTransferItems
     * @return Response
     */
    public function transferStock(StockTransfer $stockTransfer, array $stockTransferItems): Response
    {
        $data = new GoodsStockTransfer($stockTransfer, $stockTransferItems);
        return $this->send($data, "T139","UraEfrisSdk\Product\StockTransferItem[]");
    }

    public function fiscalizeInvoice(Invoice $invoice): Response
    {
        return $this->send($invoice, "T109", InvoiceResponse::class);
    }


    public function retrieveInvoice(string $invoiceNo): Response
    {
        $query = array("invoiceNo" => $invoiceNo);
        return $this->send($query, "T108", InvoiceResponse::class);
    }

    public function queryInvoice(InvoiceQuery $invoiceQuery): Response
    {
        return $this->send($invoiceQuery, "T106", PagedQueryResponse::class);
    }

    public function issueCreditNote(CreditNote $creditNote): Response
    {
        return $this->send($creditNote, "T110", CreditNoteResponse::class);
    }

    public function queryCreditNote(CreditNoteQuery $creditNoteQuery): Response
    {
        return $this->send($creditNoteQuery, "T111", PagedQueryResponse::class);
    }

    public function retrieveCreditNote(string $id): Response
    {
        return $this->send(array("id" => $id), "T112", CreditNoteResponse::class);
    }

    public function cancelCreditNote(CancelNote $cancelNote): Response
    {
        return $this->send($cancelNote, "T114", "array", true);
    }

    public function tinInfo(string $tin): Response
    {
        $query = array();
        $query['tin'] = $tin;
        return $this->send($query, "T119", TaxpayerInfo::class, true);
    }


    /**
     * @param Payload $payload
     * @param $type
     * @param $aesKey
     * @return Response
     * @throws EFRISException
     */
    private function extractResponse(Payload $payload, string $type, $aesKey): Response
    {
        $response = Response::builder()->returnStateInfo($payload->returnStateInfo);
//        check encryption stata
        $isEncrypted = $payload->data->dataDescription->codeType == "1";
        $encryptCode = $payload->data->dataDescription->encryptCode;
        if ($isEncrypted and $encryptCode == "2") {
            if ($aesKey == null)
                $aesKey = self::getAESKey();
            $payload->data->decrypt($aesKey);
        } else {
            $jsonContent = base64_decode($payload->data->content);
            if ($payload->globalInfo->interfaceCode == "T104") {
                if ($payload->returnStateInfo->returnCode != "00") {
                    throw new EFRISException($payload->returnStateInfo->returnMessage, data: $response->data(""));
                }
                $passowrdDes = base64_decode(json_decode($jsonContent)->passowrdDes);
                $response->data(base64_decode(Crypto::rsaDecrypt($passowrdDes)));
            } else {
                $response->data(self::json_deserialize($jsonContent, 'array'));
            }
            return $response;
        }
        if (gettype($payload->data->content) == "string" and empty($payload->data->content))
            $response->data($payload->data->content);
        else
            $response->data(EFRISService::json_deserialize($payload->data->content, $type));

        return $response;
    }

    /**
     * @param string $interfaceCode
     * @param Data $data
     * @param $type
     * @param bool|string|null $aesKey
     * @return false|Response
     * @throws EFRISException
     */
    public function post(string $interfaceCode, Data $data, string $type, bool|string|null $aesKey): false|Response
    {

        $globalInfo = new GlobalInfo($this->tin, $this->deviceNo, $interfaceCode);
        $payload = new Payload(globalInfo: $globalInfo, data: $data); //::build()->data($data)->globalInfo($globalInfo);

        $curl = curl_init("https://efrisws.ura.go.ug/ws/taapp/getInformation");
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $this->json_serialize($payload));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curl);
        curl_close($curl);
        if ($response) {
            $payload = self::json_deserialize($response, Payload::class);
            // self::json_deserialize($response, Payload::class);
            return self::extractResponse($payload, $type, $aesKey);
        }
        return false;
    }


    /**
     * @throws JsonException
     */
    private static function customJsonEncode($data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    private static function customJsonDecode(string $json): mixed
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (isset($decoded['taxpayerType'])) {
            $decoded['taxpayerType'] = TaxpayerType::fromJson($decoded['taxpayerType']);
        }
        return $decoded;
    }

}
