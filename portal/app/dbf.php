<?php
// Lector de tablas dBase / Visual FoxPro (.DBF) del ERP. Lee registro a registro para no cargar
// tablas grandes en memoria. Ignora los campos memo (no se suben los .FPT) y las filas borradas.
declare(strict_types=1);

final class DbfReader
{
    /** @var resource */
    private $fh;
    private int $records;
    private int $headerLength;
    private int $recordLength;
    /** @var array<int, array{name:string,type:string,length:int,decimals:int,offset:int}> */
    private array $fields = [];

    public function __construct(string $path, private string $encoding = 'Windows-1252')
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("No se puede abrir $path");
        }
        $this->fh = $fh;
        $header = fread($fh, 32);
        if (strlen($header) < 32) {
            throw new RuntimeException(basename($path) . ' no es un fichero DBF válido');
        }
        $h = unpack('Cversion/C3date/Vrecords/vheaderLength/vrecordLength', $header);
        $this->records = $h['records'];
        $this->headerLength = $h['headerLength'];
        $this->recordLength = $h['recordLength'];

        $offset = 1; // el primer byte de cada registro es la marca de borrado
        while (true) {
            $desc = fread($fh, 32);
            if ($desc === '' || $desc === false || $desc[0] === "\r") {
                break;
            }
            $name = rtrim(substr($desc, 0, 11), "\0 ");
            $field = [
                'name' => strtoupper($name),
                'type' => $desc[11],
                'length' => ord($desc[16]),
                'decimals' => ord($desc[17]),
                'offset' => $offset,
            ];
            $offset += $field['length'];
            $this->fields[] = $field;
        }
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    /** @return string[] */
    public function fieldNames(): array
    {
        return array_column($this->fields, 'name');
    }

    /** @return Generator<array<string, mixed>> */
    public function rows(): Generator
    {
        fseek($this->fh, $this->headerLength);
        for ($i = 0; $i < $this->records; $i++) {
            $raw = fread($this->fh, $this->recordLength);
            if ($raw === false || strlen($raw) < $this->recordLength) {
                return;
            }
            if ($raw[0] === '*') {
                continue;
            }
            $row = [];
            foreach ($this->fields as $f) {
                $row[$f['name']] = $this->value($f, substr($raw, $f['offset'], $f['length']));
            }
            yield $row;
        }
    }

    private function value(array $f, string $raw)
    {
        switch ($f['type']) {
            case 'C':
                $text = rtrim($raw, " \0");
                return $text === '' ? '' : mb_convert_encoding($text, 'UTF-8', $this->encoding);
            case 'N':
            case 'F':
                $text = trim($raw, " \0");
                return ($text === '' || !is_numeric($text)) ? null : (float) $text;
            case 'L':
                $c = strtoupper($raw[0] ?? '');
                return in_array($c, ['T', 'Y'], true) ? true : (in_array($c, ['F', 'N'], true) ? false : null);
            case 'D':
                $text = trim($raw);
                return preg_match('/^\d{8}$/', $text) ? substr($text, 0, 4) . '-' . substr($text, 4, 2) . '-' . substr($text, 6, 2) : null;
            case 'I':
                return unpack('l', $raw)[1];
            case 'B':
                return unpack('e', $raw)[1];
            default:
                return null; // memo (M), fecha-hora (T), flags internos (0)...
        }
    }
}
