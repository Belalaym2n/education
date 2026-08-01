<?php

namespace App\Database;

use PDO;
use PDOException;

class Database
{
  private $pdo;

  public function
  __construct($dsn, $username, $password)
  {
    try {
      $this->pdo = new PDO(
        $dsn,
        $username,
        $password
      );
      $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      die("Connection Error: " . $e->getMessage());
    }
  }

  // CREATE
  public function insert($table, $data)
  {
    $columns = implode(", ", array_keys($data));
    $values = implode(", ", array_fill(0, count($data), "?"));

    $sql = "INSERT INTO $table ($columns) VALUES ($values)";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(array_values($data));

    return $this->pdo->lastInsertId();
  }

  // READ
  public function select($table, $where = null)
  {
    if ($where) {
      $sql = "SELECT * FROM $table WHERE $where";
      $stmt = $this->pdo->query($sql);
    } else {
      $sql = "SELECT * FROM $table";
      $stmt = $this->pdo->query($sql);
    }

    return $stmt->fetchAll();
  }

  // READ ONE
  public function selectOne(string $sql, array $params = []): ?array
  {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result === false) {
      return null;
    }

    return $result;
  }

  // UPDATE 
public function update(string $table, array $data, string $where, array $params = [])
{
    $set = implode(', ', array_map(
        fn($key) => "$key = ?",
        array_keys($data)
    ));

    $sql = "UPDATE $table SET $set WHERE $where";

    $stmt = $this->pdo->prepare($sql);

    $stmt->execute([
        ...array_values($data),
        ...$params,
    ]);

    return $stmt->rowCount();
}
  // DELETE
  public function delete($table, $id)
  {
    $sql = "DELETE FROM $table WHERE id = ?";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([$id]);
    return $stmt->rowCount();
  }

  // Custom Query
  public function query($sql, $params = [])
  {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
  }


  // ===== NEW: Transactions =====
  public function beginTransaction(): bool
  {
    return $this->pdo->beginTransaction();
  }

  public function commit(): bool
  {
    return $this->pdo->commit();
  }

  public function rollBack(): bool
  {
    if ($this->pdo->inTransaction()) {
      return $this->pdo->rollBack();
    }
    return false;
  }

  public function inTransaction(): bool
  {
    return $this->pdo->inTransaction();
  }

  // مفيد لما تحتاج WHERE أعقد من عمود=قيمة بسيط (زي BY email)
  public function selectOneWhere(string $table, string $where, array $params = []): ?array
  {
    return $this->selectOne("SELECT * FROM $table WHERE $where", $params);
  }
  public function execute(string $sql, array $params = []): int
  {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
  }
} 
