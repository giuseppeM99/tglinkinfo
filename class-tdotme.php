<?php

class tdotme
{
    private $html;
    private $url;
    private $invite;
    private $username;
    private $porn = NULL;
    private static $curl = NULL;
    private static function getCurl()
    {
        if (self::$curl === NULL)
            self::$curl = curl_init();
        return self::$curl;
    }

    private static function fgc($url)
    {
        $ch = self::getCurl();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        return curl_exec($ch);
    }

    public function __construct($url)
    {
        $username = self::isValidUsername($url);
        if ($username) {
            $this->username = $username;
            $this->url = "https://t.me/".$this->username;
        } elseif (preg_match("#t(?:elegram|lgrm)?\.(?:me|dog)#i",$url)) {
            $this->url = (strpos($url, 'http') === 0) ? $url : 'https://'.$url;
        } else {
          throw new \Error("INVALID URL $url");
        }
        if (preg_match("#joinchat/([\w-\+]+)#i", $this->url, $res)) {
            $this->invite = $res[1];
        } elseif (preg_match("#me/([\w\d_]{5,32})#i", $this->url, $res)) {
            $this->username = $res[1];
        } else {
            throw new \Error("INVALID URL $url");
        }
        $this->html = @self::fgc($this->url);

        return $this;
    }

    public static function isValidUsername($username)
    {

        $username = str_replace('@','',$username);
        if(in_array(strtolower($username), ["bing", "bold", "cap", "coub", "gif", "imdb", "like", "pic", "vid", "vote", "wiki", "ya"])) {
            return $username;
        }
        if(preg_match("#@([\w\d_]{5,32})$#", "@$username", $res)) {
            return $res[1];
        } else {
            return false;
        }
    }

    public static function base64url_encode($data, $pad = null)
    {
        $data = str_replace(array('+', '/'), array('-', '_'), base64_encode($data));
        if (!$pad) {
            $data = rtrim($data, '=');
        }
        return $data;
    }

    public static function base64url_decode($data)
    {
        return base64_decode(str_replace(array('-', '_'), array('+', '/'), $data));
    }

    public function getInvite()
    {
        return $this->invite;
    }

    public function getMembers()
    {
        if($this->isUser() || ! preg_match('#"tgme_page_extra">([\d\s]+) members#i', $this->html, $rr)) return 0;
        $members = $rr[1];
        return intval(str_replace(' ', '',$members));
    }

    public function getTitle()
    {
        preg_match('#meta property="og:title" content="(.+)"#', $this->html, $rr);
        return htmlspecialchars_decode($rr[1]);
    }

    public function getAbout()
    {
        preg_match('#meta property="og:description" content="(.+)"#', $this->html, $rr);
        if (empty($rr[1])) {
            $rr[1] = "";
        }
        return htmlspecialchars_decode($rr[1]);
    }

    public function getImage()
    {
        preg_match('#meta property="og:image" content="(.+)"#', $this->html, $rr);
        if (isset($rr[1])) {
            return htmlspecialchars_decode($rr[1]);
        } else {
            return null;
        }
    }

    public function isValid()
    {
        if (empty($this->getAbout()) && $this->getImage() == "https://telegram.org/img/t_logo.png") {
            if(!empty($this->invite) && $this->getTitle() === "Join group chat on Telegram")
                return false;
            if(!empty($this->username) && $this->getTitle() !== "Telegram: Contact @".$this->username && !$this->isPornBlocked())
                return false;
        }
        return true;
    }

    public function getCreator()
    {
        if (empty($this->invite) || strpos($this->invite, "AAAA") === 0) {
            return 0;
        }
        $d = strrev(self::base64url_decode($this->invite));
        @$chat_id = unpack("i", substr($d, 12))[1];
        return $chat_id;
    }

    public function isChannel()
    {
        if (!empty($this->username) && stripos($this->html, "href=\"tg://resolve?domain=".$this->username."\">view channel") > 2500) {
            return true;
        }
        if (empty($this->invite) || !(strpos($this->invite, "AAAA") === 0)) {
            return false;
        }
        if ($this->isSuperGroup()) {
            return false;
        }
        return true;
    }

    public function isGroup()
    {
        if (empty($this->invite) || $this->isChannel() || !$this->isValid()) {
            return false;
        }
        if (strpos($this->html, "href=\"tg://join?invite=".$this->invite."\">Join Channel") > 2500) {
            return true;
        }
        return false;
    }

    public function isSuperGroup()
    {
        if ($this->isValid() && !empty($this->username) && stripos($this->html, "href=\"tg://resolve?domain=".$this->username."\">view group") > 2500) {
            return true;
        }
        if ($this->isValid() && strpos($this->html, "href=\"tg://join?invite=".$this->invite."\">Join Group") > 2500) {
            return true;
        }
        return false;
    }

    public function isUser()
    {
        if ($this->isValid() && !$this->isPornBlocked() && !empty($this->username) && stripos($this->html, "href=\"tg://resolve?domain=".$this->username."\">send message") > 2500) {
            return true;
        }
        return false;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getChatID()
    {
        if (empty($this->invite)) {
            return 0;
        }
        $d = strrev(self::base64url_decode($this->invite));
        @$chat_id = unpack("i", substr($d, 8))[1];
        if ($this->isGroup()) {
            $chat_id = "-$chat_id";
        } elseif ($this->isSuperGroup() || $this->isChannel()) {
            $chat_id = "-100$chat_id";
        }
        $chat_id = $chat_id *1;
        return $chat_id;
    }

    public function isPornBlocked()
    {
        if($this->porn !== NULL)
            return $this->porn;
        $dom = new DOMDocument();
        @$dom->loadHTML(self::fgc($this->url."/1?embed=1"));
        $node = $dom->getElementById("widget_message");
        $text = "";
        if ($node)
        $text = trim($node->nodeValue);
        if ($text === "This channel is blocked because it was used to spread pornographic content.") {
            $this->porn = true;
        } else {
            $this->porn = false;
        }
        return $this->porn;
    }

    public function __destruct()
    {
        unset($this->html);
        unset($this->url);
        unset($this->invite);
        unset($this->username);
    }
}
