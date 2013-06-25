<?php

namespace Translatable;

use Doctrine\Common\EventManager;
use Doctrine\ORM\Query;
use Tool\BaseTestCaseORM;
use Fixture\Translatable\Post;
use Fixture\Translatable\PostTranslation;
use Fixture\Translatable\Comment;
use Fixture\Translatable\CommentTranslation;
use Gedmo\Translatable\TranslatableListener;
use Gedmo\Translatable\Query\TreeWalker\TranslationWalker;
use Doctrine\Common\Cache\ArrayCache;

class QueryWalkerTest extends BaseTestCaseORM
{
    const SQL_WALKER = 'Gedmo\Translatable\Query\TreeWalker\TranslationWalker';

    private $translatable;

    protected function setUp()
    {
        parent::setUp();

        $evm = new EventManager();
        $evm->addEventSubscriber($this->translatable = new TranslatableListener());
        $conn = array(
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'dbname' => 'test',
            'user' => 'root',
            'password' => 'nimda',
        );
        /* $this->getMockCustomEntityManager($conn, $evm); */
        $this->getMockSqliteEntityManager($evm);
        $this->populate();
    }

    /**
     * @test
     */
    public function shouldHandleQueryCache()
    {
        $this->em
            ->getConfiguration()
            ->expects($this->any())
            ->method('getQueryCacheImpl')
            ->will($this->returnValue($cache = new ArrayCache()));

        $q = $this->em->createQuery('SELECT p FROM Fixture\Translatable\Post p');
        $q->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::SQL_WALKER);
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'en');

        // array hydration
        $this->startQueryLog();
        $result = $q->getArrayResult();
        $this->assertEquals(1, $this->queryAnalyzer->getNumExecutedQueries());
        $this->assertCount(1, $result);

        $q2 = clone $q;
        $q2->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::SQL_WALKER);
        $q2->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'en');
        $this->queryAnalyzer->cleanUp();
        $result = $q2->getArrayResult();
        $this->assertEquals(1, $this->queryAnalyzer->getNumExecutedQueries());
        $this->assertCount(1, $result);
    }

    /**
     * @test
     */
    public function shouldHandleSubselectWithTranslatedField()
    {
        $this->translatable->setTranslatableLocale('lt');
        $cars = new Post();
        $cars->setTitle('Masinos');
        $cars->setContent('apie masinas');

        $cmt = new Comment();
        $cmt->setPost($cars);
        $cmt->setMessage('nice post');
        $cmt->setSubject('thanks');
        $cmt->setRating(4);

        $this->em->persist($cars);
        $this->em->persist($cmt);
        $this->em->flush();
        $this->em->clear();

        $dql = <<<DQL
    SELECT p FROM Fixture\Translatable\Post p
    WHERE p.title IN (
        SELECT p2.title FROM Fixture\Translatable\Post p2
        WHERE p2.content LIKE 'apie%'
    )
    ORDER BY p.title
DQL;
        $q = $this->em->createQuery($dql);
        $q->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::SQL_WALKER);
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'lt');

        // array hydration
        $result = $q->getArrayResult();
        $this->assertCount(2, $result);
        $this->assertSame('Maistas', $result[0]['title']);
        $this->assertSame('Masinos', $result[1]['title']);
    }

    /**
     * @test
     */
    public function shouldBeAbleToUseInnerJoinStrategyForTranslations()
    {
        $q = $this->em->createQuery('SELECT p FROM Fixture\Translatable\Post p');
        $q->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::SQL_WALKER);
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'ru');
        $q->setHint(TranslatableListener::HINT_INNER_JOIN, true);

        // array hydration
        $result = $q->getArrayResult();
        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function shouldSelectWithTranslationFallbackOnObjectHydration()
    {
        $this->em
            ->getConfiguration()
            ->expects($this->any())
            ->method('getCustomHydrationMode')
            ->with(TranslationWalker::HYDRATE_OBJECT_TRANSLATION)
            ->will($this->returnValue('Gedmo\Translatable\Hydrator\ORM\ObjectHydrator'));

        $q = $this->em->createQuery('SELECT p FROM Fixture\Translatable\Post p');
        $q->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::SQL_WALKER);
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'ru');

        // object hydration
        $this->startQueryLog();
        $result = $q->getResult();
        $this->assertEquals(1, $this->queryAnalyzer->getNumExecutedQueries());
        $this->assertEquals(null, $result[0]->getTitle());
        $this->assertEquals(null, $result[0]->getContent());

        $q->setHint(TranslatableListener::HINT_FALLBACK, array('en'));
        $this->queryAnalyzer->cleanUp();
        $result = $q->getResult();
        $this->assertEquals(1, $this->queryAnalyzer->getNumExecutedQueries());
        $this->assertEquals('Food', $result[0]->getTitle());
        $this->assertEquals('about food', $result[0]->getContent());

        // test multiple fallbacks
        $q->setHint(TranslatableListener::HINT_FALLBACK, array('undef', 'lt'));
        $this->queryAnalyzer->cleanUp();
        $result = $q->getResult();
        $this->assertEquals(1, $this->queryAnalyzer->getNumExecutedQueries());
        $this->assertEquals('Maistas', $result[0]->getTitle());
        $this->assertEquals('apie maista', $result[0]->getContent());
    }

    /**
     * @test
     */
    public function shouldTranslateCountStatement()
    {
        $dql = <<<DQL
    SELECT COUNT(p)
    FROM Fixture\Translatable\Post p
    WHERE p.title LIKE :title
DQL;
        $q = $this->em->createQuery($dql);
        $q->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::SQL_WALKER);

        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'en');
        $q->setParameter('title', 'Foo%');
        $result = $q->getSingleScalarResult();
        $this->assertSame(1, intval($result));

        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'lt');
        $q->setParameter('title', 'Mais%');
        $result = $q->getSingleScalarResult();
        $this->assertSame(1, intval($result));

        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'en');
        $q->setParameter('title', 'Mai%');
        $result = $q->getSingleScalarResult();
        $this->assertSame(0, intval($result));
    }

    /**
     * @test
     */
    public function shouldTranslateSecondJoinedComponentTranslation()
    {
        $this->em
            ->getConfiguration()
            ->expects($this->any())
            ->method('getCustomHydrationMode')
            ->with(TranslationWalker::HYDRATE_OBJECT_TRANSLATION)
            ->will($this->returnValue('Gedmo\Translatable\Hydrator\ORM\ObjectHydrator'));

        $dql = <<<DQL
    SELECT p, c
    FROM Fixture\Translatable\Post p
    LEFT JOIN p.comments c
    ORDER BY c.rating DESC
DQL;

        $q = $this->em->createQuery($dql);
        $q->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::SQL_WALKER);
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'en');

        // array hydration
        $food = $q->getArrayResult();
        $this->assertSame('Food', $food[0]['title']);
        $this->assertSame('about food', $food[0]['content']);
        $comments = $food[0]['comments'];
        $this->assertCount(2, $comments);
        $this->assertSame('good', $comments[0]['subject']);
        $this->assertSame('food is good', $comments[0]['message']);
        $this->assertSame(4, $comments[0]['rating']);
        $this->assertSame('bad', $comments[1]['subject']);
        $this->assertSame('food is bad', $comments[1]['message']);
        $this->assertSame(1, $comments[1]['rating']);

        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'lt');
        $food = $q->getArrayResult();
        $this->assertSame('Maistas', $food[0]['title']);
        $this->assertSame('apie maista', $food[0]['content']);
        $comments = $food[0]['comments'];
        $this->assertCount(2, $comments);
        $this->assertSame('blogas', $comments[0]['subject']);
        $this->assertSame('maistas yra blogas', $comments[0]['message']);
        $this->assertSame(4, $comments[0]['rating']);
        $this->assertSame('geras', $comments[1]['subject']);
        $this->assertSame('maistas yra geras', $comments[1]['message']);
        $this->assertSame(2, $comments[1]['rating']);

        // object hydration
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'en');
        $food = $q->getResult();
        $this->assertSame('Food', $food[0]->getTitle());
        $this->assertSame('about food', $food[0]->getContent());
        $comments = $food[0]->getComments();
        $this->assertCount(2, $comments);
        $this->assertSame('good', $comments[0]->getSubject());
        $this->assertSame('food is good', $comments[0]->getMessage());
        $this->assertSame(4, $comments[0]->getRating());
        $this->assertSame('bad', $comments[1]->getSubject());
        $this->assertSame('food is bad', $comments[1]->getMessage());
        $this->assertSame(1, $comments[1]->getRating());

        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'lt');
        $food = $q->getResult();
        $this->assertSame('Maistas', $food[0]->getTitle());
        $this->assertSame('apie maista', $food[0]->getContent());
        $comments = $food[0]->getComments();
        $this->assertCount(2, $comments);
        $this->assertSame('blogas', $comments[0]->getSubject());
        $this->assertSame('maistas yra blogas', $comments[0]->getMessage());
        $this->assertSame(4, $comments[0]->getRating());
        $this->assertSame('geras', $comments[1]->getSubject());
        $this->assertSame('maistas yra geras', $comments[1]->getMessage());
        $this->assertSame(2, $comments[1]->getRating());
    }

    /**
     * @test
     */
    public function shouldTranslatePartialComponentTranslation()
    {
        $this->em
            ->getConfiguration()
            ->expects($this->any())
            ->method('getCustomHydrationMode')
            ->with(TranslationWalker::HYDRATE_OBJECT_TRANSLATION)
            ->will($this->returnValue('Gedmo\Translatable\Hydrator\ORM\ObjectHydrator'));

        $dql = 'SELECT p.title FROM Fixture\Translatable\Post p';
        $q = $this->em->createQuery($dql);
        $q->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::SQL_WALKER);
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'en');

        // array hydration
        $result = $q->getArrayResult();
        $this->assertCount(1, $result);
        $this->assertCount(1, $result[0]);
        $this->assertEquals('Food', $result[0]['title']);

        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'lt');
        $result = $q->getArrayResult();
        $this->assertCount(1, $result);
        $this->assertCount(1, $result[0]);
        $this->assertEquals('Maistas', $result[0]['title']);

        // object hydration
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'en');
        $result = $q->getResult();
        $this->assertCount(1, $result);
        $this->assertCount(1, $result[0]);
        $this->assertEquals('Food', $result[0]['title']);

        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'lt');
        $result = $q->getResult();
        $this->assertCount(1, $result);
        $this->assertCount(1, $result[0]);
        $this->assertEquals('Maistas', $result[0]['title']);
    }

    /**
     * @expectedException \Gedmo\Exception\RuntimeException
     * @test
     */
    public function shouldThrowAnExceptionIfLocaleIsNotSet()
    {
        $q = $this->em->createQuery('SELECT p FROM Fixture\Translatable\Post p');
        $q->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::SQL_WALKER);
        $q->getArrayResult();
    }

    /**
     * @test
     */
    public function shouldIgnoreUnmappedField()
    {
        $dql = 'SELECT p.title, count(p.id) AS num FROM Fixture\Translatable\Post p ORDER BY p.title';
        $q = $this->em->createQuery($dql);
        $q->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::SQL_WALKER);
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'en');

        // array hydration
        $result = $q->getArrayResult();
        $this->assertCount(1, $result);
        $this->assertEquals('Food', $result[0]['title']);
        $this->assertEquals(1, $result[0]['num']);
    }

    /**
     * @test
     */
    public function shouldTranslateSingleComponentQuery()
    {
        $this->em
            ->getConfiguration()
            ->expects($this->any())
            ->method('getCustomHydrationMode')
            ->with(TranslationWalker::HYDRATE_OBJECT_TRANSLATION)
            ->will($this->returnValue('Gedmo\Translatable\Hydrator\ORM\ObjectHydrator'));

        $q = $this->em->createQuery('SELECT p FROM Fixture\Translatable\Post p');
        $q->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::SQL_WALKER);
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'en');

        // array hydration - en
        $result = $q->getArrayResult();
        $this->assertCount(1, $result, "There should be one Post fetched");
        $this->assertEquals('Food', $result[0]['title']);
        $this->assertEquals('about food', $result[0]['content']);
        // array hydration - lt
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'lt');
        $result = $q->getArrayResult();
        $this->assertCount(1, $result);
        $this->assertEquals('Maistas', $result[0]['title']);
        $this->assertEquals('apie maista', $result[0]['content']);

        // object hydration - en
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'en');
        $result = $q->getResult();
        $this->assertCount(1, $result);
        $this->assertEquals('Food', $result[0]->getTitle());
        $this->assertEquals('about food', $result[0]->getContent());
        // object hydration - lt
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'lt');
        $result = $q->getResult();
        $this->assertCount(1, $result);
        $this->assertEquals('Maistas', $result[0]->getTitle());
        $this->assertEquals('apie maista', $result[0]->getContent());
    }

    private function populate()
    {
        $food = new Post();
        $food->setTitle('Food');
        $food->setContent('about food');

        $goodFood = new Comment();
        $goodFood->setPost($food);
        $goodFood->setMessage('food is good');
        $goodFood->setSubject('good');
        $goodFood->setRating(4);

        $badFood = new Comment();
        $badFood->setPost($food);
        $badFood->setMessage('food is bad');
        $badFood->setSubject('bad');
        $badFood->setRating(1);

        $this->em->persist($food);
        $this->em->persist($goodFood);
        $this->em->persist($badFood);
        $this->em->flush();

        $this->translatable->setTranslatableLocale('lt');
        $food->setTitle('Maistas');
        $food->setContent('apie maista');

        $goodFood->setMessage('maistas yra geras');
        $goodFood->setSubject('geras');
        $goodFood->setRating(2);

        $badFood->setMessage('maistas yra blogas');
        $badFood->setSubject('blogas');
        $badFood->setRating(4);

        $this->em->persist($food);
        $this->em->persist($goodFood);
        $this->em->persist($badFood);
        $this->em->flush();
        $this->em->clear();
    }

    protected function getUsedEntityFixtures()
    {
        return array(
            'Fixture\Translatable\Post',
            'Fixture\Translatable\PostTranslation',
            'Fixture\Translatable\Comment',
            'Fixture\Translatable\CommentTranslation',
        );
    }
}
