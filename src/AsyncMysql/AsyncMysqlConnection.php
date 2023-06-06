<?hh // strict

namespace Slack\SQLFake;

use namespace HH\Lib\{SQL, Vec};

<<__MockClass>>
final class AsyncMysqlConnection extends \AsyncMysqlConnection {

	private bool $open = true;
	private bool $reusable = true;
	private AsyncMysqlConnectResult $result;

	/**
	 * Not part of the built-in AsyncMysqlConnection
	 */
	private Server $server;
	private QueryStringifier $queryStringifier;

	public function getServer(): Server {
		return $this->server;
	}

	public function getDatabase(): string {
		return $this->dbname;
	}

	public function setDatabase(string $dbname): void {
		$this->dbname = $dbname;
	}

	/* HH_IGNORE_ERROR[3012] I don't want to call parent::construct */
	public function __construct(private string $host, private int $port, private string $dbname, ?QueryStringifier $query_stringifier = null) {
		$this->server = Server::getOrCreate($host);
		$this->result = new AsyncMysqlConnectResult(false);
		$this->queryStringifier = $query_stringifier ?? QueryStringifier::createForTypesafeHack();
	}

	<<__Override>>
	public async function query(
		string $query,
		int $_timeout_micros = -1,
		dict<string, string> $_query_attributes = dict[],
	): Awaitable<AsyncMysqlQueryResult> {
		Logger::log(Verbosity::QUERIES, "SQLFake [verbose]: $query");
		QueryContext::$query = $query;

		$config = $this->server->config;
		$strict_sql_before = QueryContext::$strictSQLMode;
		if ($config['strict_sql_mode'] ?? false) {
			QueryContext::$strictSQLMode = true;
		}

		$strict_schema_before = QueryContext::$strictSchemaMode;
		if ($config['strict_schema_mode'] ?? false) {
			QueryContext::$strictSchemaMode = true;
		}

		if (($config['inherit_schema_from'] ?? '') !== '') {
			$this->dbname = $config['inherit_schema_from'] ?? '';
		}

		try {
			list($results, $rows_affected) = SQLCommandProcessor::execute($query, $this);
		} catch (\Exception $e) {
			QueryContext::$strictSQLMode = $strict_sql_before;
			QueryContext::$strictSchemaMode = $strict_schema_before;
			// Make debugging a failing unit test locally easier,
			// by showing the actual query that failed parsing along with the parser error
			$msg = $e->getMessage();
			$type = \get_class($e);
			Logger::log(Verbosity::QUIET, $e->getFile().' '.$e->getLine());
			Logger::log(Verbosity::QUIET, "SQL Fake $type: $msg in SQL query: $query");
			throw $e;
		}
		QueryContext::$strictSQLMode = $strict_sql_before;
		QueryContext::$strictSchemaMode = $strict_schema_before;
		Logger::logResult($this->getServer()->name, $results, $rows_affected);
		return new AsyncMysqlQueryResult(vec($results), $rows_affected);
	}

	<<__Override>>
	public async function queryAsync(SQL\Query $query): Awaitable<AsyncMysqlQueryResult> {
		return await $this->query($this->queryStringifier->formatQuery($query));
	}

	<<__Override>>
	public function queryf(
		\HH\FormatString<\HH\SQLFormatter> $query,
		mixed ...$args
	): Awaitable<AsyncMysqlQueryResult> {
		invariant($query is string, '\\HH\\FormatString<_> is opaque, but we need it to be a string here.');
		return $this->query($this->queryStringifier->formatString($query, vec($args)));
	}

	<<__Override>>
	public async function multiQuery(
		Traversable<string> $queries,
		int $_timeout_micros = -1,
		dict<string, string> $_query_attributes = dict[],
	): Awaitable<Vector<AsyncMysqlQueryResult>> {
		$results = await Vec\map_async($queries, $query ==> $this->query($query));
		return Vector::fromItems($results);
	}

	<<__Override>>
	public function escapeString(string $data): string {
		// not actually escaping obviously
		return $data;
	}

	<<__Override>>
	public function close(): void {
		$this->open = false;
	}

	<<__Override>>
	public function releaseConnection(): void {}

	<<__Override>>
	public function isValid(): bool {
		return $this->open;
	}

	<<__Override>>
	public function serverInfo(): string {
		// Copied from https://docs.hhvm.com/hack/reference/class/AsyncMysqlConnection/serverInfo/
		return '5.6.24-fb-log-slackhq-sql-fake';
	}

	<<__Override>>
	public function warningCount(): int {
		return 0;
	}

	<<__Override>>
	public function host(): string {
		return $this->host;
	}

	<<__Override>>
	public function port(): int {
		return $this->port;
	}

	<<__Override>>
	public function setReusable(bool $reusable): void {
		$this->reusable = $reusable;
	}

	<<__Override>>
	public function isReusable(): bool {
		return $this->reusable;
	}

	<<__Override>>
	public function lastActivityTime(): float {
		// A float representing the number of seconds ago since epoch that we had successful activity on the current connection.
		// 50 ms ago seems like a reasonable answer.
		return \microtime(true) - 0.05;
	}

	<<__Override>>
	public function connectResult(): \AsyncMysqlConnectResult {
		return $this->result;
	}
}
