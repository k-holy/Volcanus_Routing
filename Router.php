<?php
/**
 * Volcanus libraries for PHP
 *
 * @copyright 2011-2013 k-holy <k.holy74@gmail.com>
 * @license The MIT License (MIT)
 */
namespace Volcanus\Routing;

use Volcanus\Routing\Exception\NotFoundException;

/**
 * Router
 *
 * @package Volcanus\Routing
 * @author k.holy74@gmail.com
 */
class Router
{

	/**
	 * @var Router Singletonインスタンス
	 */
	private static $instance = null;

	/**
	 * @var string RequestURI解析パターン
	 */
	private static $requestUriPattern = '~\A(?:[^:/?#]+:)*(?://(?:[^/?#]*))*([^?#]*)(?:\?([^#]*))?(?:#(.*))?\z~i';

	/**
	 * @var array 設定値
	 */
	private $config;

	/**
	 * @var array $_SERVER環境変数
	 */
	private $server;

	/**
	 * @var Parser
	 */
	private $parser;

	/**
	 * @var array ディレクトリパラメータ
	 */
	private $parameters;

	/**
	 * @var string 転送先ディレクトリ
	 */
	private $translateDirectory;

	/**
	 * @var string 読込対象ファイル
	 */
	private $includeFile;

	/**
	 * @var string 仮想URI (読込対象ファイル + PathInfo + QueryString)
	 */
	private $virtualUri;

	/**
	 * @var string リクエストされた拡張子
	 */
	private $extension;

	/**
	 * @var boolean ルーティング準備処理済みかどうか
	 */
	private $prepared;

	/**
	 * constructor
	 *
	 * @param array 設定オプション
	 */
	public function __construct(array $configurations = array())
	{
		$this->initialize($configurations);
	}

	/**
	 * オブジェクトを初期化します。
	 *
	 * @param array 設定オプション
	 */
	public function initialize(array $configurations = array())
	{
		$this->config = array(
			'parameterDirectoryName'  => '%VAR%',
			'searchExtensions'        => 'php',
			'overwriteGlobals'        => true,
			'parameterLeftDelimiter'  => null,
			'parameterRightDelimiter' => null,
			'parameterFilters'        => array(),
			'fallbackScript'          => null,
		);
		$this->server = array(
			'DOCUMENT_ROOT'   => null,
			'PATH_INFO'       => null,
			'PATH_TRANSLATED' => null,
			'PHP_SELF'        => null,
			'REQUEST_URI'     => null,
			'SCRIPT_FILENAME' => null,
			'SCRIPT_NAME'     => null,
		);
		$this->parser = new Parser();
		$this->parameters = array();
		$this->translateDirectory = null;
		$this->includeFile = null;
		$this->virtualUri = null;
		$this->extension = null;
		$this->prepared = false;
		if (!empty($configurations)) {
			foreach ($configurations as $name => $value) {
				$this->config($name, $value);
			}
		}
		return $this;
	}

	/**
	 * Singletonインスタンスを返します。
	 *
	 * @param array デフォルトの設定
	 * @return object Router
	 */
	public static function instance(array $configurations = array())
	{
		if (!isset(self::$instance)) {
			self::$instance = new self($configurations);
		}
		return self::$instance;
	}

	/**
	 * __clone()
	 */
	private final function __clone()
	{
		throw new \RuntimeException('could not clone instance.');
	}

	/**
	 * 引数1つの場合は指定された設定の値を返します。
	 * 引数2つの場合は指定された設置の値をセットして$thisを返します。
	 *
	 * string parameterDirectoryName : パラメータディレクトリ名
	 * string searchExtensions       : ルーティングによる読み込み対象ファイルの拡張子
	 * bool   overwriteGlobals       : $_SERVER グローバル変数をフィールドに含まれる囲み文字のエスケープ文字 ※1文字のみ対応
	 *
	 * @param string 設定名
	 * @return mixed 設定値 または $this
	 * @throws \InvalidArgumentException
	 */
	public function config($name)
	{
		if (!array_key_exists($name, $this->config)) {
			throw new \InvalidArgumentException(
				sprintf('The config key "%s" could not accept.', $name)
			);
		}
		switch (func_num_args()) {
		case 1:
			return $this->config[$name];
		case 2:
			$value = func_get_arg(1);
			if (isset($value)) {
				switch ($name) {
				case 'parameterDirectoryName':
				case 'searchExtensions':
				case 'parameterLeftDelimiter':
				case 'parameterRightDelimiter':
				case 'fallbackScript':
					if (!is_string($value)) {
						throw new \InvalidArgumentException(
							sprintf('The config parameter "%s" only accepts string.', $name));
					}
					break;
				case 'overwriteGlobals':
					if (is_int($value) || ctype_digit($value)) {
						$value = (bool)$value;
					}
					if (!is_bool($value)) {
						throw new \InvalidArgumentException(
							sprintf('The config parameter "%s" only accepts boolean.', $name));
					}
					break;
				case 'parameterFilters':
					if (!is_array($value)) {
						throw new \InvalidArgumentException(
							sprintf('The config parameter "%s" only accepts array.', $name));
					}
					break;
				}
				$this->config[$name] = $value;
			}
			return $this;
		}
		throw new \InvalidArgumentException('Invalid arguments count.');
	}

	/**
	 * 引数1つの場合は指定された環境変数の値を返します。
	 * 引数2つの場合は指定された環境変数の値をセットして$thisを返します。
	 *
	 * REQUEST_URIが不正な場合は値を受け付けず例外をスローします。
	 * DOCUMENT_ROOTは値に含まれるディレクトリ区切り文字を / に統一します。
	 *
	 * @param string 環境変数名
	 * @return mixed 環境変数値 または $this
	 * @throws \InvalidArgumentException
	 */
	public function server($name)
	{
		if (!array_key_exists($name, $this->server)) {
			throw new \InvalidArgumentException(
				sprintf('The server vars "%s" could not accept.', $name));
		}
		switch (func_num_args()) {
		case 1:
			return $this->server[$name];
		case 2:
			$value = func_get_arg(1);
			if (isset($value)) {
				if (!is_string($value)) {
					throw new \InvalidArgumentException(
						sprintf('The server vars "%s" is not string.', $name));
				}
				switch ($name) {
				case 'REQUEST_URI':
					if (!preg_match(self::$requestUriPattern, $value, $matches)) {
						throw new \InvalidArgumentException(
							sprintf('The server vars "%s" is not valid. "%s"', $name, $value));
					}
					break;
				case 'DOCUMENT_ROOT':
					if (DIRECTORY_SEPARATOR !== '/') {
						$value = str_replace(DIRECTORY_SEPARATOR, '/', $value);
					}
					break;
				}
				$this->server[$name] = $value;
			}
			return $this;
		}
		throw new \InvalidArgumentException('Invalid arguments count.');
	}

	/**
	 * $_SERVERグローバル変数から環境変数を取り込みます。
	 *
	 * @return object Router
	 */
	public function importGlobals()
	{
		if (isset($_SERVER)) {
			foreach ($_SERVER as $name => $value) {
				if (array_key_exists($name, $this->server)) {
					$this->server($name, $value);
				}
			}
		}
		return $this;
	}

	/**
	 * 全てのパラメータを配列で返します。
	 *
	 * @return array パラメータの配列
	 */
	public function parameters()
	{
		return $this->parameters;
	}

	/**
	 * 指定されたパスパラメータの値を返します。
	 *
	 * @param int パラメータのインデックス
	 * @param mixed デフォルト値
	 * @return mixed パラメータ値
	 */
	public function parameter($index, $defaultValue = null)
	{
		if (array_key_exists($index, $this->parameters)) {
			return $this->parameters[$index];
		}
		return $defaultValue;
	}

	/**
	 * 転送先ディレクトリを返します。
	 *
	 * @return string
	 */
	public function translateDirectory()
	{
		return $this->translateDirectory;
	}

	/**
	 * 読込対象スクリプトの物理パスを返します。
	 *
	 * @return string
	 */
	public function includeFile()
	{
		return $this->includeFile;
	}

	/**
	 * 仮想URI (読込対象スクリプトのドキュメントルートからのパス + PathInfo + QueryString) を返します。
	 *
	 * @return string
	 */
	public function virtualUri()
	{
		return $this->virtualUri;
	}

	/**
	 * リクエストされた拡張子を返します。
	 *
	 * @return string
	 */
	public function extension()
	{
		return $this->extension;
	}

	/**
	 * リクエストURIを解析し、ルーティングの実行を準備します。
	 *
	 * @param string requestURI
	 * @return object Router
	 * @throws \RuntimeException
	 * @throws Exception\NotFoundException
	 * @throws Exception\InvalidParameterException
	 */
	public function prepare($requestUri = null)
	{
		if (isset($requestUri)) {
			$this->server('REQUEST_URI', $requestUri);
		}

		$requestUri = $this->server('REQUEST_URI');
		if (!isset($requestUri)) {
			throw new \RuntimeException('RequestUri is not set.');
		}

		$documentRoot = $this->server('DOCUMENT_ROOT');
		if (!isset($documentRoot)) {
			throw new \RuntimeException('DocumentRoot is not set.');
		}

		$fallbackScript = $this->config['fallbackScript'];
		if (isset($fallbackScript)) {
			if (!file_exists($documentRoot . $fallbackScript)) {
				throw new \RuntimeException(
					sprintf('fallbackScript "%s" could not found in DocumentRoot "%s"', $fallbackScript, $documentRoot)
				);
			}
		}

		preg_match(self::$requestUriPattern, $requestUri, $matches);

		$requestPath = (isset($matches[1])) ? $matches[1] : '';
		$queryString = (isset($matches[2])) ? $matches[2] : '';
		$fragment    = (isset($matches[3])) ? $matches[3] : '';

		// リクエストパスの解析はParserクラスに委譲
		$this->parser->initialize(array(
			'documentRoot'            => $documentRoot,
			'parameterDirectoryName'  => $this->config['parameterDirectoryName'],
			'parameterLeftDelimiter'  => $this->config['parameterLeftDelimiter'],
			'parameterRightDelimiter' => $this->config['parameterRightDelimiter'],
			'searchExtensions'        => $this->config['searchExtensions'],
			'parameterFilters'        => $this->config['parameterFilters'],
		));

		try {
			$parsed = $this->parser->parse($requestPath);
		} catch (NotFoundException $exception) {
			// fallbackScriptが設定されている場合はスクリプトを検索
			if (isset($fallbackScript)) {
				$parsed = $this->parser->parse($fallbackScript);
			} else {
				throw $exception;
			}
		}

		$includeFile = $parsed['translateDirectory'] . '/' . $parsed['filename'];

		$this->extension  = $parsed['extension'];
		$this->parameters = $parsed['parameters'];

		if (strlen($parsed['pathInfo']) >= 1) {
			$this->server('PATH_INFO'      , $parsed['pathInfo']);
			$this->server('PATH_TRANSLATED', $documentRoot . $parsed['pathInfo']);
		}

		if (strlen($parsed['scriptName']) >= 1) {
			$this->server('SCRIPT_NAME'    , $parsed['scriptName']);
			$this->server('PHP_SELF'       , $parsed['scriptName'] . $parsed['pathInfo']);
			$this->server('SCRIPT_FILENAME', $documentRoot . $parsed['scriptName']);
		}

		$this->translateDirectory = $documentRoot . $parsed['translateDirectory'];

		$this->includeFile = $documentRoot . $includeFile;

		$this->virtualUri = $includeFile;

		if (strlen($parsed['pathInfo']) >= 1) {
			$this->virtualUri .= $parsed['pathInfo'];
		}
		if (strlen($queryString) >= 1) {
			$this->virtualUri .= '?' . $queryString;
		}

		$this->prepared = true;

		return $this;
	}

	/**
	 * ルーティングを実行します。
	 * カレントディレクトリを移動し、対象のスクリプトを返します。
	 * overwriteGlobalsオプションが有効な場合、$_SERVERグローバル変数を
	 * serverの値で上書きします。
	 *
	 * @param string requestURI
	 * @return string Router
	 */
	public function execute($requestUri = null)
	{
		if (!$this->prepared) {
			$this->prepare($requestUri);
		}
		if ($this->config['overwriteGlobals']) {
			$this->overwriteGlobals();
		}
		if (isset($this->translateDirectory)) {
			chdir($this->translateDirectory);
		}
		include $this->includeFile;
	}

	/**
	 * 環境変数を$_SERVERグローバル変数に上書きします。
	 *
	 * @return object Router
	 */
	private function overwriteGlobals()
	{
		if (isset($_SERVER)) {
			foreach ($this->server as $name => $value) {
				$_SERVER[$name] = $value;
			}
		}
		return $this;
	}

}
