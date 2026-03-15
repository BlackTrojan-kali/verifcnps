<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Reporting CNPS</title>
    <style>
        body { 
            font-family: sans-serif; 
            font-size: 10px; /* Police plus petite pour tout faire rentrer */
            color: #000; 
        }
        
        .filters-section {
            margin-bottom: 10px;
            font-size: 11px;
        }

        table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        
        th, td { 
            border: 1px solid #000; /* Bordures noires fines comme sur l'image */
            padding: 4px; 
            text-align: left; 
        }

        /* Le fameux bleu de l'en-tête de votre image */
        .table-header-blue {
            background-color: #9bc2e6; 
            font-weight: bold;
            text-align: center;
        }

        th { 
            background-color: #bdd7ee; /* Bleu légèrement plus clair pour les colonnes */
            font-size: 10px;
            text-align: center;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
    </style>
</head>
<body>

    <div class="filters-section">
        <strong>Début période :</strong> {{ $request->start_date ? \Carbon\Carbon::parse($request->start_date)->format('d-m-Y') : '---' }} &nbsp;&nbsp;&nbsp;&nbsp;
        <strong>Fin période :</strong> {{ $request->end_date ? \Carbon\Carbon::parse($request->end_date)->format('d-m-Y') : '---' }}
    </div>

    <table>
        <thead>
            <tr>
                <td colspan="10" class="table-header-blue">
                    PERIODE DU {{ $request->start_date ? \Carbon\Carbon::parse($request->start_date)->format('d-m-Y') : 'DEBUT' }} A {{ $request->end_date ? \Carbon\Carbon::parse($request->end_date)->format('d-m-Y') : "AUJOURD'HUI" }}
                </td>
            </tr>
            <tr>
                <th>N°</th>
                <th>Etab financier / Mode</th>
                <th>code centre</th>
                <th>Type assure</th>
                <th>N° Assuré (NIU)</th>
                <th>Nom / raison sociale</th>
                <th>Telephone</th>
                <th>Ref Paiement</th>
                <th>Montant</th>
                <th>date paiement</th>
            </tr>
        </thead>
        <tbody>
            @foreach($declarations as $index => $dec)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                
                <td>{{ $dec->bank ? $dec->bank->nom_banque : strtoupper($dec->payment_mode ?? 'NON DÉFINI') }}</td>
                
                <td class="text-center">310</td>
                <td class="text-center">EM</td>
                
                <td>{{ $dec->company->niu ?? 'N/A' }}</td>
                <td>{{ $dec->company->raison_sociale ?? 'N/A' }}</td>
                <td>{{ $dec->company->telephone ?? 'N/A' }}</td>
                <td>{{ $dec->reference }}</td>
                <td class="text-right">{{ number_format($dec->amount, 0, ',', '') }}</td>
                <td class="text-center">{{ $dec->created_at->format('d-m-Y') }}</td>
            </tr>
            @endforeach
            
            <tr>
                <td colspan="8" class="text-right" style="font-weight: bold; background-color: #f2f2f2;">TOTAL GENERAL</td>
                <td class="text-right" style="font-weight: bold; background-color: #f2f2f2;">{{ number_format($totalAmount, 0, ',', '') }}</td>
                <td style="background-color: #f2f2f2;"></td>
            </tr>
        </tbody>
    </table>

</body>
</html>