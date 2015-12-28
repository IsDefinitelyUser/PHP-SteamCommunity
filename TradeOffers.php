<?php
/**
 * Created by PhpStorm.
 * User: Joel
 * Date: 2015-12-27
 * Time: 4:41 PM
 */

namespace waylaidwanderer\SteamCommunity;


use waylaidwanderer\SteamCommunity\TradeOffers\TradeOffer;

class TradeOffers
{
    const BASE_URL = 'http://steamcommunity.com/my/tradeoffers/';

    protected $steamCommunity;

    public function __construct(SteamCommunity $steamCommunity)
    {
        $this->steamCommunity = $steamCommunity;
    }

    /**
     * @return TradeOffer[]
     */
    public function getIncomingOffers()
    {
        $url = self::BASE_URL;
        return $this->parseTradeOffers($this->steamCommunity->cURL($url), false);
    }

    /**
     * @return TradeOffer[]
     */
    public function getIncomingOfferHistory()
    {
        $url = self::BASE_URL . '?history=1';
        return $this->parseTradeOffers($this->steamCommunity->cURL($url), false);
    }

    /**
     * @return TradeOffer[]
     */
    public function getSentOffers()
    {
        $url = self::BASE_URL . 'sent/';
        return $this->parseTradeOffers($this->steamCommunity->cURL($url), true);
    }

    /**
     * @return TradeOffer[]
     */
    public function getSentOfferHistory()
    {
        $url = self::BASE_URL . 'sent/?history=1';
        return $this->parseTradeOffers($this->steamCommunity->cURL($url), true);
    }

    /**
     * @param $html
     * @param $isOurOffer
     * @return TradeOffer[]
     */
    private function parseTradeOffers($html, $isOurOffer)
    {
        libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        $doc->loadHTML($html);
        $xpath = new \DOMXPath($doc);

        $tradeOffers = [];
        /** @var \DOMElement[] $tradeOfferElements */
        $tradeOfferElements = $xpath->query('//div[@id[starts-with(.,"tradeofferid_")]]');
        foreach ($tradeOfferElements as $tradeOfferElement) {
            $tradeOffer = new TradeOffer();
            $tradeOffer->setIsOurOffer($isOurOffer);

            $tradeOfferId = str_replace('tradeofferid_', '', $tradeOfferElement->getAttribute('id'));
            $tradeOffer->setTradeOfferId($tradeOfferId);

            $primaryItemsElement = $xpath->query('.//div[contains(@class, "tradeoffer_items primary")]', $tradeOfferElement)->item(0);
            $itemsToGiveList = $xpath->query('.//div[contains(@class, "tradeoffer_item_list")]/div[contains(@class, "trade_item")]', $primaryItemsElement);
            $itemsToGive = [];
            /** @var \DOMElement[] $itemsToGiveList */
            foreach ($itemsToGiveList as $itemToGive) {
                //classinfo/570/583164181/93973071
                //         appId/classId/instanceId
                //570/2/7087209304/76561198045552709
                //appId/contextId/assetId/steamId
                $item = new TradeOffer\Item();
                $itemInfo = explode('/', $itemToGive->getAttribute('data-economy-item'));
                if ($itemInfo[0] == 'classinfo') {
                    $item->setAppId($itemInfo[1]);
                    $item->setClassId($itemInfo[2]);
                    if (isset($itemInfo[3])) {
                        $item->setInstanceId($itemInfo[3]);
                    }
                } else {
                    $item->setAppId($itemInfo[0]);
                    $item->setContextId($itemInfo[1]);
                    $item->setAssetId($itemInfo[2]);
                }
                if (strpos($itemToGive->getAttribute('class'), 'missing') !== false) {
                    $item->setMissing(true);
                }
                $itemsToGive[] = $item;
            }
            $tradeOffer->setItemsToGive($itemsToGive);

            $secondaryItemsElement = $xpath->query('.//div[contains(@class, "tradeoffer_items secondary")]', $tradeOfferElement)->item(0);;

            $otherAccountId = $xpath->query('.//a[@data-miniprofile]/@data-miniprofile', $secondaryItemsElement)->item(0)->nodeValue;
            $tradeOffer->setOtherAccountId($otherAccountId);

            $itemsToReceiveList = $xpath->query('.//div[contains(@class, "tradeoffer_item_list")]/div[contains(@class, "trade_item")]', $secondaryItemsElement);
            $itemsToReceive = [];
            /** @var \DOMElement[] $itemsToReceiveList */
            foreach ($itemsToReceiveList as $itemToReceive) {
                $item = new TradeOffer\Item();
                $itemInfo = explode('/', $itemToReceive->getAttribute('data-economy-item'));
                if ($itemInfo[0] == 'classinfo') {
                    $item->setAppId($itemInfo[1]);
                    $item->setClassId($itemInfo[2]);
                    if (isset($itemInfo[3])) {
                        $item->setInstanceId($itemInfo[3]);
                    }
                } else {
                    $item->setAppId($itemInfo[0]);
                    $item->setContextId($itemInfo[1]);
                    $item->setAssetId($itemInfo[2]);
                }
                if (strpos($itemToReceive->getAttribute('class'), 'missing') !== false) {
                    $item->setMissing(true);
                }
                $itemsToReceive[] = $item;
            }
            $tradeOffer->setItemsToReceive($itemsToReceive);

            // message
            $messageElement = $xpath->query('.//div[contains(@class, "tradeoffer_message")]/div[contains(@class, "quote")]', $tradeOfferElement)->item(0);
            if (!is_null($messageElement)) {
                $tradeOffer->setMessage($messageElement->nodeValue);
            }

            // expiration
            $footerElement = $xpath->query('.//div[contains(@class, "tradeoffer_footer")]', $tradeOfferElement)->item(0);
            if (!empty($footerElement->nodeValue)) {
                $expirationTimeString = str_replace('Offer expires on ', '', $footerElement->nodeValue);
                $tradeOffer->setExpirationTime(strtotime($expirationTimeString));
            }

            // state
            $bannerElement = $xpath->query('.//div[contains(@class, "tradeoffer_items_banner")]', $tradeOfferElement)->item(0);
            if (is_null($bannerElement)) {
                $tradeOffer->setTradeOfferState(TradeOffer\State::Active);
            } else {
                if (strpos($bannerElement->nodeValue, 'Awaiting Mobile Confirmation') !== false) {
                    $tradeOffer->setTradeOfferState(TradeOffer\State::NeedsConfirmation);
                    $tradeOffer->setConfirmationMethod(TradeOffer\ConfirmationMethod::MobileApp);
                } else if (strpos($bannerElement->nodeValue, 'Awaiting Email Confirmation') !== false) {
                    $tradeOffer->setTradeOfferState(TradeOffer\State::NeedsConfirmation);
                    $tradeOffer->setConfirmationMethod(TradeOffer\ConfirmationMethod::Email);
                } else if (strpos($bannerElement->nodeValue, 'Trade Offer Canceled') !== false) {
                    $tradeOffer->setTradeOfferState(TradeOffer\State::Canceled);
                    $canceledDate = strtotime(str_replace('Trade Offer Canceled ', '', $bannerElement->nodeValue));
                    if ($canceledDate !== false) {
                        $tradeOffer->setTimeUpdated($canceledDate);
                    }
                } else if (strpos($bannerElement->nodeValue, 'Trade Declined') !== false) {
                    $tradeOffer->setTradeOfferState(TradeOffer\State::Declined);
                    $declinedDate = strtotime(str_replace('Trade Declined ', '', $bannerElement->nodeValue));
                    if ($declinedDate !== false) {
                        $tradeOffer->setTimeUpdated($declinedDate);
                    }
                } else if (strpos($bannerElement->nodeValue, 'On hold') !== false) {
                    $tradeOffer->setTradeOfferState(TradeOffer\State::InEscrow);
                    $split = explode('.', $bannerElement->nodeValue);
                    $acceptedString = trim($split[0]);
                    $acceptedDate = \DateTime::createFromFormat('M j, Y @ g:ia', str_replace('Trade Accepted ', '', $acceptedString));
                    if ($acceptedDate !== false) {
                        $tradeOffer->setTimeUpdated($acceptedDate->getTimestamp());
                    }
                    $escrowString = trim($split[1]);
                    $escrowDate = \DateTime::createFromFormat('M j, Y @ g:ia', str_replace('On hold until ', '', $escrowString));
                    if ($escrowDate !== false) {
                        $tradeOffer->setEscrowEndDate($escrowDate->getTimestamp());
                    }
                } else if (strpos($bannerElement->nodeValue, 'Trade Accepted') !== false) {
                    $tradeOffer->setTradeOfferState(TradeOffer\State::Accepted);
                    // 14 Dec, 2015 @ 4:32am
                    $acceptedDate = \DateTime::createFromFormat('j M, Y @ g:ia', str_replace('Trade Accepted ', '', trim($bannerElement->nodeValue)));
                    if ($acceptedDate !== false) {
                        $tradeOffer->setTimeUpdated($acceptedDate->getTimestamp());
                    }
                } else if (strpos($bannerElement->nodeValue, 'Items Now Unavailable For Trade') !== false) {
                    $tradeOffer->setTradeOfferState(TradeOffer\State::InvalidItems);
                } else if (strpos($bannerElement->nodeValue, 'Counter Offer Made') !== false) {
                    $tradeOffer->setTradeOfferState(TradeOffer\State::Countered);
                    $counteredDate = strtotime(str_replace('Counter Offer Made ', '', $bannerElement->nodeValue));
                    if ($counteredDate !== false) {
                        $tradeOffer->setTimeUpdated($counteredDate);
                    }
                } else if (strpos($bannerElement->nodeValue, 'Trade Offer Expired') !== false) {
                    $tradeOffer->setTradeOfferState(TradeOffer\State::Expired);
                    $expiredDate = strtotime(str_replace('Trade Offer Expired ', '', $bannerElement->nodeValue));
                    if ($expiredDate !== false) {
                        $tradeOffer->setTimeUpdated($expiredDate);
                    }
                } else {
                    $tradeOffer->setTradeOfferState(TradeOffer\State::Invalid);
                }
            }

            $tradeOffers[] = $tradeOffer;
        }
        return $tradeOffers;
    }
}