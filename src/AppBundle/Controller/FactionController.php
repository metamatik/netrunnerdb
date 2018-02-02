<?php

namespace AppBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FactionController extends Controller
{
    public function factionAction($faction_code, EntityManagerInterface $entityManager)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->getParameter('short_cache'));

        if ($faction_code === 'mini-factions') {
            $factions = $entityManager->getRepository('AppBundle:Faction')->findBy(['isMini' => true], ['code' => 'ASC']);
            $faction_name = "Mini-factions";
        } else {
            $factions = $entityManager->getRepository('AppBundle:Faction')->findBy(['code' => $faction_code]);
            if (!count($factions)) {
                throw new NotFoundHttpException("Faction $faction_code not found.");
            }
            $faction_name = $factions[0]->getName();
        }


        $result = [];

        foreach ($factions as $faction) {

            // build the list of identites for the faction

            $qb = $entityManager->createQueryBuilder();
            $qb->select('c')
               ->from('AppBundle:Card', 'c')
               ->join('c.pack', 'p')
               ->where('c.faction=:faction')
               ->setParameter('faction', $faction)
               ->andWhere('c.type=:type')
               ->andWhere('p.dateRelease is not null')
               ->setParameter('type', $entityManager->getRepository('AppBundle:Type')->findOneBy(['code' => 'identity']));

            $identities = $qb->getQuery()->getResult();

            $nb_decklists_per_id = 3;

            // build the list of the top $nb_decklists_per_id decklists per id
            // also, compute the total points of those decks per id

            $decklists = [];
            foreach ($identities as $identity) {
                $qb = $entityManager->createQueryBuilder();
                $qb->select('d, (d.nbvotes/(1+DATE_DIFF(CURRENT_TIMESTAMP(),d.dateCreation)/10)) as points')
                   ->from('AppBundle:Decklist', 'd')
                   ->where('d.identity=:identity')
                   ->setParameter('identity', $identity)
                   ->orderBy('points', 'DESC')
                   ->setMaxResults($nb_decklists_per_id);
                $results = $qb->getQuery()->getResult();

                $points = 0;
                $list = [];
                foreach ($results as $row) {
                    $list[] = $row[0];
                    $points += intval($row['points']);
                }

                $decklists[] = [
                    'identity'  => $identity,
                    'points'    => $points,
                    'decklists' => $list,
                ];
            }

            // sort the identities from most points to least
            usort($decklists, function ($a, $b) {
                return $b['points'] - $a['points'];
            });

            $result[] = [
                'faction'   => $faction,
                'decklists' => $decklists,
            ];
        }

        return $this->render('/Faction/faction.html.twig', [
            "pagetitle" => "Faction Page: $faction_name",
            "results"   => $result,
        ], $response);
    }
}
