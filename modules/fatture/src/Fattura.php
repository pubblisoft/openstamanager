<?php

namespace Modules\Fatture;

use Common\Document;
use Modules\Anagrafiche\Anagrafica;
use Modules\Pagamenti\Pagamento;
use Modules\RitenuteContributi\RitenutaContributi;
use Plugins\ExportFE\FatturaElettronica;
use Traits\RecordTrait;
use Util\Generator;

class Fattura extends Document
{
    use RecordTrait;

    protected $table = 'co_documenti';

    protected $casts = [
        'bollo' => 'float',
    ];

    /**
     * Crea una nuova fattura.
     *
     * @param Anagrafica $anagrafica
     * @param Tipo       $tipo_documento
     * @param string     $data
     * @param int        $id_segment
     *
     * @return self
     */
    public static function build(Anagrafica $anagrafica, Tipo $tipo_documento, $data, $id_segment)
    {
        $model = parent::build();

        $stato_documento = Stato::where('descrizione', 'Bozza')->first();

        $id_anagrafica = $anagrafica->id;
        $direzione = $tipo_documento->dir;

        $database = database();

        if ($direzione == 'entrata') {
            $id_conto = setting('Conto predefinito fatture di vendita');
            $conto = 'vendite';
        } else {
            $id_conto = setting('Conto predefinito fatture di acquisto');
            $conto = 'acquisti';
        }

        // Tipo di pagamento e banca predefinite dall'anagrafica
        $id_pagamento = $database->fetchOne('SELECT id FROM co_pagamenti WHERE id = :id_pagamento', [
            ':id_pagamento' => $anagrafica['idpagamento_'.$conto],
        ])['id'];
        $id_banca = $anagrafica['idbanca_'.$conto];

        $split_payment = $anagrafica->split_payment;

        // Se la fattura è di vendita e non è stato associato un pagamento predefinito al cliente leggo il pagamento dalle impostazioni
        if ($direzione == 'entrata' && empty($id_pagamento)) {
            $id_pagamento = setting('Tipo di pagamento predefinito');
        }

        // Se non è impostata la banca dell'anagrafica, uso quella del pagamento.
        if (empty($id_banca)) {
            $id_banca = $database->fetchOne('SELECT id FROM co_banche WHERE id_pianodeiconti3 = (SELECT idconto_'.$conto.' FROM co_pagamenti WHERE id = :id_pagamento)', [
                ':id_pagamento' => $id_pagamento,
            ])['id'];
        }

        $id_sede = $anagrafica->idsede_fatturazione;

        $model->anagrafica()->associate($anagrafica);
        $model->tipo()->associate($tipo_documento);
        $model->stato()->associate($stato_documento);

        $model->save();

        // Salvataggio delle informazioni
        $model->data = $data;
        $model->id_segment = $id_segment;

        $model->idconto = $id_conto;
        $model->idsede = $id_sede;

        $id_ritenuta_contributi = ($tipo_documento->dir == 'entrata') ? setting('Ritenuta contributi') : null;
        $model->id_ritenuta_contributi = $id_ritenuta_contributi ?: null;

        if (!empty($id_pagamento)) {
            $model->idpagamento = $id_pagamento;
        }
        if (!empty($id_banca)) {
            $model->idbanca = $id_banca;
        }

        if (!empty($split_payment)) {
            $model->split_payment = $split_payment;
        }

        $model->save();

        return $model;
    }

    /**
     * Imposta il sezionale relativo alla fattura e calcola il relativo numero.
     * **Attenzione**: la data deve inserita prima!
     *
     * @param int $value
     */
    public function setIdSegmentAttribute($value)
    {
        $previous = $this->id_segment;

        $this->attributes['id_segment'] = $value;

        // Calcolo dei numeri fattura
        if ($value != $previous) {
            $direzione = $this->tipo->dir;
            $data = $this->data;

            $this->numero = static::getNextNumero($data, $direzione, $value);
            $this->numero_esterno = static::getNextNumeroSecondario($data, $direzione, $value);
        }
    }

    /**
     * Restituisce il nome del modulo a cui l'oggetto è collegato.
     *
     * @return string
     */
    public function getModuleAttribute()
    {
        return $this->tipo->dir == 'entrata' ? 'Fatture di vendita' : 'Fatture di acquisto';
    }

    // Calcoli

    /**
     * Calcola il netto a pagare della fattura.
     *
     * @return float
     */
    public function getNettoAttribute()
    {
        return $this->calcola('netto') + $this->bollo;
    }

    /**
     * Calcola la rivalsa INPS totale della fattura.
     *
     * @return float
     */
    public function getRivalsaINPSAttribute()
    {
        return $this->calcola('rivalsa_inps');
    }

    /**
     * Calcola l'IVA totale della fattura.
     *
     * @return float
     */
    public function getIvaAttribute()
    {
        return $this->calcola('iva', 'iva_rivalsa_inps');
    }

    /**
     * Calcola l'iva della rivalsa INPS totale della fattura.
     *
     * @return float
     */
    public function getIvaRivalsaINPSAttribute()
    {
        return $this->calcola('iva_rivalsa_inps');
    }

    /**
     * Calcola la ritenuta d'acconto totale della fattura.
     *
     * @return float
     */
    public function getRitenutaAccontoAttribute()
    {
        return $this->calcola('ritenuta_acconto');
    }

    public function getTotaleRitenutaContributiAttribute()
    {
        return $this->calcola('ritenuta_contributi');
    }

    // Relazioni Eloquent

    public function anagrafica()
    {
        return $this->belongsTo(Anagrafica::class, 'idanagrafica');
    }

    public function tipo()
    {
        return $this->belongsTo(Tipo::class, 'idtipodocumento');
    }

    public function pagamento()
    {
        return $this->belongsTo(Pagamento::class, 'idpagamento');
    }

    public function stato()
    {
        return $this->belongsTo(Stato::class, 'idstatodocumento');
    }

    public function statoFE()
    {
        return $this->belongsTo(StatoFE::class, 'codice_stato_fe');
    }

    public function articoli()
    {
        return $this->hasMany(Components\Articolo::class, 'iddocumento');
    }

    public function righe()
    {
        return $this->hasMany(Components\Riga::class, 'iddocumento');
    }

    public function descrizioni()
    {
        return $this->hasMany(Components\Descrizione::class, 'iddocumento');
    }

    public function scontoGlobale()
    {
        return $this->hasOne(Components\Sconto::class, 'iddocumento');
    }

    public function ritenutaContributi()
    {
        return $this->belongsTo(RitenutaContributi::class, 'id_ritenuta_contributi');
    }

    // Metodi generali

    public function getXML()
    {
        if (empty($this->progressivo_invio) && $this->module == 'Fatture di acquisto') {
            $fe = new FatturaElettronica($this->id);

            return $fe->toXML();
        }

        $file = $this->uploads()->where('name', 'Fattura Elettronica')->first();

        return file_get_contents($file->filepath);
    }

    public function isFE()
    {
        return !empty($this->progressivo_invio);
    }

    /**
     * Registra le scadenze della fattura elettronica collegata al documento.
     *
     * @param bool $is_pagato
     *
     * @return bool
     */
    public function registraScadenzeFE($is_pagato = false)
    {
        $xml = \Util\XML::read($this->getXML());

        $pagamenti = $xml['FatturaElettronicaBody']['DatiPagamento']['DettaglioPagamento'];
        if (!empty($pagamenti)) {
            $rate = isset($pagamenti[0]) ? $pagamenti : [$pagamenti];

            foreach ($rate as $rata) {
                $scadenza = $rata['DataScadenzaPagamento'] ?: $this->data;
                $importo = -$rata['ImportoPagamento'];

                self::registraScadenza($this, $importo, $scadenza, $is_pagato);
            }
        }

        return !empty($pagamenti);
    }

    /**
     * Registra le scadenze tradizionali del gestionale.
     *
     * @param bool $is_pagato
     */
    public function registraScadenzeTradizionali($is_pagato = false)
    {
        $rate = $this->pagamento->calcola($this->netto, $this->data);
        $direzione = $this->tipo->dir;

        foreach ($rate as $rata) {
            $importo = $direzione == 'uscita' ? -$rata['importo'] : $rata['importo'];
            $scadenza = $rata['scadenza'];

            self::registraScadenza($this, $importo, $scadenza, $is_pagato);
        }
    }

    /**
     * Registra una specifica scadenza nel database.
     *
     * @param Fattura $fattura
     * @param float   $importo
     * @param string  $scadenza
     * @param bool    $is_pagato
     * @param string  $type
     */
    public static function registraScadenza(Fattura $fattura, $importo, $scadenza, $is_pagato, $type = 'fattura')
    {
        database()->insert('co_scadenziario', [
            'iddocumento' => $fattura->id,
            'data_emissione' => $fattura->data,
            'scadenza' => $scadenza,
            'da_pagare' => $importo,
            'tipo' => $type,
            'pagato' => $is_pagato ? $importo : 0,
            'data_pagamento' => $is_pagato ? $scadenza : null,
        ]);
    }

    /**
     * Registra le scadenze della fattura.
     *
     * @param bool $is_pagato
     * @param bool $ignora_fe
     */
    public function registraScadenze($is_pagato = false, $ignora_fe = false)
    {
        $this->rimuoviScadenze();

        if (!$ignora_fe && $this->module == 'Fatture di acquisto' && $this->isFE()) {
            $scadenze_fe = $this->registraScadenzeFE($is_pagato);
        }

        if (empty($scadenze_fe)) {
            $this->registraScadenzeTradizionali($is_pagato);
        }

        $direzione = $this->tipo->dir;
        $ritenuta_acconto = $this->ritenuta_acconto;

        // Se c'è una ritenuta d'acconto, la aggiungo allo scadenzario
        if ($direzione == 'uscita' && $ritenuta_acconto > 0) {
            $scadenza = date('Y-m', strtotime($data.' +1 month')).'-15';
            $importo = -$ritenuta_acconto;

            self::registraScadenza($this, $importo, $scadenza, $is_pagato, 'ritenutaacconto');
        }
    }

    /**
     * Elimina le scadenze della fattura.
     */
    public function rimuoviScadenze()
    {
        database()->delete('co_scadenziario', ['iddocumento' => $this->id]);
    }

    /**
     * Restituisce l'elenco delle note di credito collegate.
     *
     * @return iterable
     */
    public function getNoteDiAccredito()
    {
        return self::where('ref_documento', $this->id)->get();
    }

    /**
     * Restituisce l'elenco delle note di credito collegate.
     *
     * @return self
     */
    public function getFatturaOriginale()
    {
        return self::find($this->ref_documento);
    }

    /**
     * Controlla se la fattura è una nota di credito.
     *
     * @return bool
     */
    public function isNotaDiAccredito()
    {
        return $this->tipo->reversed == 1;
    }

    public function updateSconto()
    {
        // Aggiornamento sconto
        aggiorna_sconto([
            'parent' => 'co_documenti',
            'row' => 'co_righe_documenti',
        ], [
            'parent' => 'id',
            'row' => 'iddocumento',
        ], $this->id);
    }

    // Metodi statici

    /**
     * Calcola il nuovo numero di fattura.
     *
     * @param string $data
     * @param string $direzione
     * @param int    $id_segment
     *
     * @return string
     */
    public static function getNextNumero($data, $direzione, $id_segment)
    {
        if ($direzione == 'entrata') {
            return '';
        }

        // Recupero maschera per questo segmento
        $maschera = Generator::getMaschera($id_segment);

        $ultimo = Generator::getPreviousFrom($maschera, 'co_documenti', 'numero', [
            'YEAR(data) = '.prepare(date('Y', strtotime($data))),
            'id_segment = '.prepare($id_segment),
        ]);
        $numero = Generator::generate($maschera, $ultimo, 1, Generator::dateToPattern($data));

        return $numero;
    }

    /**
     * Calcola il nuovo numero secondario di fattura.
     *
     * @param string $data
     * @param string $direzione
     * @param int    $id_segment
     *
     * @return string
     */
    public static function getNextNumeroSecondario($data, $direzione, $id_segment)
    {
        if ($direzione == 'uscita') {
            return '';
        }

        // Recupero maschera per questo segmento
        $maschera = Generator::getMaschera($id_segment);

        $ultimo = Generator::getPreviousFrom($maschera, 'co_documenti', 'numero_esterno', [
            'YEAR(data) = '.prepare(date('Y', strtotime($data))),
            'id_segment = '.prepare($id_segment),
        ]);
        $numero = Generator::generate($maschera, $ultimo, 1, Generator::dateToPattern($data));

        return $numero;
    }
}
