<?php
/**
 * Simples Script para conexao Web Socket em PHP
 *
 * Especificacao WebSocket em : http://tools.ietf.org/html/rfc6455
 *
 * Agradecendo a Sann-Remy pois a logica da aplicacao foi obtida atraves do seu blog
 * http://srchea.com/blog/2011/12/build-a-real-time-application-using-html5-websockets/
 *
 * @author Cleber Rodrigues <cleber@cleberrodrigues.com.br>
 *
 * XXX TODO :: este script serve apenas para homologacao da solucao
 * XXX TODO :: Verificar versao do Web Socket, suportado apenas versao 13,
 */

// desativa o timeout do PHP
set_time_limit(0);

// Inicia a Classe de Web Socket
$socket = new WebSocket();

// Roda o Servidor
$socket->run();

/**
 * Classe responsavel por criar o Socket no Server
 */
class WebSocket {
    
    /**
     * Objeto do Socket principal
     * @var Object
     */
    private $_socket = null;

    /**
     * Lista de arquivos sockets ja conectados
     * @var Array
     */
    private $_sockets = array();

    /**
     * Lista de conexoes
     * @var Array
     */
    private $_connections = array();

    /**
     * Construtor,
     * Cria o Server, escutando por conexoes
     *
     * @param String $address Endereco de escuta
     * @param Int    $port    Porta de escuta
     *
     * @return void
     */
    public function __construct($address = "10.0.1.182", $port = 5001)
    {

        print " Iniciando socket ... ";

        // criamos o server SOCKET, utilizando os parametros:
        // AF_INET     :: IPv4 para TCP
        // SOCK_STREAM :: conexao sequencial baseda em "byte streams"
        // SOL_TCP     :: Protocolo TCP
        if (($this->_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
            $code = socket_last_error($this->_socket);
            exit (sprintf("Falha ao conectar: %s (%s)", socket_strerror($code), $code));
        }

        print " feito \n";

        socket_set_option($this->_socket, SOL_SOCKET, SO_REUSEADDR, 1);

        printf(" Escutando em %s:%s ... ", $address, $port);

        // passando os dados que iremos escutar nosso socket
        if (socket_bind($this->_socket, $address, $port) === false) {
            $code = socket_last_error($this->_socket);
            exit (sprintf("erro ao escutar em %s:%s: %s (%s)", $address, $port, socket_strerror($code), $code));
        }

        print " feito \n";

        // aguardamos por escuta
        if (socket_listen($this->_socket) === false) {
            $code = socket_last_error($this->_socket);
            exit (sprintf("erro ao escutar em %s:%s: %s (%s)", $address, $port, socket_strerror($code), $code));
        }

        // adiciona esta Conexao a lista de sockets
        $this->_sockets = array($this->_socket);

        // XXX FIXME :: Quick Fix para correcao Bug no php menor que 5.3
        // https://bugs.php.net/bug.php?id=48326
        if (!defined('MSG_DONTWAIT')) {
            define('MSG_DONTWAIT', 0x40);
            return 1;
        }
    }
    
    /**
     * Aguarda as conexoes dos Clientes,
     * realizando o handshake com o Browser
     *
     * @return void
     */
    public function run() {

        print " Aguardando conexoes ... \n";

        do {

            $listSockets = $this->_sockets;

            // passa a lista de socket ap socket_select, 
            // criando um timeout de conexao
            socket_select($listSockets, $write = NULL, $except = NULL, 5);

            foreach ($listSockets as $socket) {

                // se o Socket listado for a atual da conexao,
                // estamos em uma nova conexao
                if ($socket == $this->_socket) {

                    // aceitamos conexoes para este novo socket
                    $acceptedSocket = socket_accept($this->_socket);

                    // Adicionamos este socket a lista de Sockets ja existentes
                    $this->_sockets[] = $acceptedSocket;

                    // Adicionamos este Socket a lista de conexoes realizadas
                    // e necessario estes dois arrays pois preciamos identificar
                    // se em uma conexao cliente ja foi realizada a regociacao
                    // de conexao entre o cliente e o server
                    $this->_connections[] = new Connection($acceptedSocket);

                    print " Novo Socket Criado  \n";

                } else {

                    // Tratando com a conexao Cliente ja estabelecida

                    // obtendo o cliente conectado
                    print " Identificando Conexao cliente  ... ";
                    foreach ($this->_connections as $conn) {

                        // se o Socket do cliente for o mesmo que o atual
                        // da lista, para de percorrer e Ficamos com esta
                        // instancia da classe cliente mesmo
                        if ($conn->getSocket() == $socket) {
                            print "  feito  \n";
                            break;
                        }
                    }

                    // recebendo os dados enviado pelo Cliente
                    print "\t recebendo dados do Cliente  .. \n";
                    if (false !== ($bytes = socket_recv($socket, $dataReceive, 2048, MSG_DONTWAIT))) {
                        echo " feito";
                    } else {
                        echo " falhou : " . socket_strerror(socket_last_error($socket)) . "\n";
                    }

                    
                    // Se nao estiver setado a flag handshake deste cliente
                    // ainda nao foi realizada a negociacao de conexao Web Socket
                    if (!$conn->handshake) {

                        printf (" Realizando Handshake ... ");
                        socket_write($socket, self::_handshake($dataReceive));
                        $conn->handshake = true;
                        printf (" feito \n");

                    // se nao receber nenhum byte, o cliente fechou a conexao
                    // de forma inesperada, entao removemos os resources das
                    // listas de conexoes e sockets estabelecidos
                    } elseif ($bytes === 0) {
                        
                        $idSocket = array_search($socket, $this->_sockets);

                        if ($idSocket >= 0) {
                            array_splice($this->_sockets, $idSocket, 1);
                            socket_close($socket);
                        }

                        $idConn = array_search($socket, $this->_connections);
                        if ($idConn >= 0) {
                            array_splice($this->_connections, $idConn, 1);
                        }

                    // se ja foi realizado o handshake, executamos a 
                    // comunicao entre o server e o client
                    } else {

                        // Obtemos o que foi enviado pelo cliente e devolvemos
                        // o mesmo texto, so teste por enquanto ;)

                        // decodicia o que recebemos do cliente
                        $text = self::_decode($dataReceive);
                        printf("\t\t Recebi: %s \n", $text);

                        // resposta
                        $text = "Voce Disse? : ". $text;
                        printf("\t\t Respondi: %s \n ", $text);
                        $text = self::_encode($text);

                        socket_write($socket, $text, strlen($text));
                    }
                }
            }
        } while (true);
    }

    /**
     * Realiza o parse do cabecalho recebido
     * retornado o cabecalho para envio ao ciente
     * confirmando o "aperto de mao" para a conexao
     *
     * @param String $data dados recebidos no cabecalho da requisicao
     *
     * @return String cabecalho que devera ser devolvido para confirmar
     *         a negociacao entre o server e o client
     */
    private function _handshake($data)
    {
        
        // faz um parse no cabecalho
        $headers = explode("\n", $data);
        $listHeaders = array();
        foreach ($headers as $line) {
            $data = explode(":", $line, 2);
            if (isset($data[0]) && isset($data[1])) {
                $listHeaders[trim($data[0])] = trim($data[1]);
            }
        }

        /*
        printf ("\t - Host: %s \n", $listHeaders["Host"]);
        printf ("\t - Sec-WebSocket-Version: %s \n", $listHeaders["Sec-WebSocket-Version"]);
        printf ("\t - Origin  %s \n", $listHeaders["Origin"]);
        printf ("\t - Sec-WebSocket-Key  %s \n", $listHeaders["Sec-WebSocket-Key"]);
        */

        // cria a chave de negociacao
        $acceptKey = base64_encode(sha1($listHeaders["Sec-WebSocket-Key"] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        
        // retorna o cabecalho de resposta
        return "HTTP/1.1 101 Switching Protocols\r\n"
             . "Upgrade: websocket\r\n"
             . "Connection: Upgrade\r\n"
             . "Sec-WebSocket-Accept: $acceptKey"
             . "\r\n\r\n";
    }

    /**
     * Decodifica o texto recebido pleo Web Socket
     *
     * @param String $dataEncode dado codificado recebido pelo Cliente
     *
     * @return String Texto decodificado para String
     *
     */
    private function _decode($dataEncode)
    {
        $length = ord($dataEncode[1]) & 127;

        if ($length == 126) {
            $masks = substr($dataEncode, 4, 4);
            $data = substr($dataEncode, 8);
        } elseif($length == 127) {
            $masks = substr($dataEncode, 10, 4);
            $data = substr($dataEncode, 14);
        } else {
            $masks = substr($dataEncode, 2, 4);
            $data = substr($dataEncode, 6);
        }

        $dataLength = strlen($data);
        $text = '';
        for ($i = 0; $i < $dataLength; ++$i) {
            $text .= $data[$i] ^ $masks[ $i % 4];
        }
        return $text;
    }

    /**
     * Codifica o texto para envio pelo Web Socket
     *
     * @param String $text Mensagem que sera enviada
     *
     * @return String Mensagem condificada senguindo padroes
     */
    private function _encode($text) {

        $b1 = 0x80 | (0x1 & 0x0f);
        $length = strlen($text);
        if ($length <= 125) {
            $header = pack('CC', $b1, $length);
        } elseif ($length > 125 && $length < 65536) {
            $header = pack('CCS', $b1, 126, $length);
        } elseif ($length >= 65536) {
            $header = pack('CCN', $b1, 127, $length);
        }
       return $header . $text;
    }
}

/**
 * Simples classe para gerenciamento de Conexoes dos clientes
 * 
 */
class Connection {
    
    /**
     * resource da conexao socket
     * @var Object
     */
    private $_socket;
    
    /**
     * Flag para marcar se ja foi realizad o handshake
     * nesta conexao
     * @var Boolean
     */
    public  $handshake = false;
    
    /**
     * Construtor,
     * Define o resource Socket desta Conexao
     *
     * @param Object $socket resource do socket
     *
     * @return void
     */
    public function __construct($socket)
    {
        $this->_socket = $socket;
    }
    
    /**
     * OBtem o resource da conexao socket
     *
     * @return Object resource do socket
     */
    public function getSocket()
    {
        return $this->_socket;
    }
}

