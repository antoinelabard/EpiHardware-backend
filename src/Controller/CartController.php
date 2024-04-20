<?php

namespace App\Controller;

use App\Entity\Cart;
use App\Entity\Order;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class CartController extends AbstractController
{
    #[Route('/api/carts/{productId}', name: 'add_product_to_cart', requirements: ['productId' => '\d+'], methods: ['POST'])]
    public function addProductToCart(int $productId, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        if (!is_int($productId)) {
            throw new \InvalidArgumentException("Product ID must be an integer.");
        }

        $product = $entityManager->getRepository(Product::class)->find($productId);
        if (!$product) {
            return new JsonResponse(['message' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        $user = $security->getUser();
        if (!$user) {
            throw new AccessDeniedException('This user does not have access to this resource.');
        }

        $cart = $entityManager->getRepository(Cart::class)->findOneBy(['user' => $user]);
        if (!$cart) {
            $cart = new Cart();
            $cart->setUser($user);
            $entityManager->persist($cart);
        }

        $cart->addProduct($product);
        $cart->setQuantityForProduct($product, ($cart->getQuantityForProduct($product) + 1));
        $entityManager->flush();

        return new JsonResponse(['status' => 'Product added to cart'], Response::HTTP_OK);
    }

    #[Route('/api/carts/{productId}', methods: ['DELETE'])]
    public function removeProductFromCart(int $productId, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $user = $security->getUser();
        if (!$user) {
            throw new AccessDeniedException('This user does not have access to this resource.');
        }

        $cart = $entityManager->getRepository(Cart::class)->findOneBy(['user' => $user]);
        if (!$cart) {
            return new JsonResponse(['message' => 'No cart found'], Response::HTTP_NOT_FOUND);
        }

        $product = $entityManager->getRepository(Product::class)->find($productId);
        if (!$product) {
            return new JsonResponse(['message' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$cart->getProducts()->contains($product)) {
            return new JsonResponse(['message' => 'Product not in cart'], Response::HTTP_NOT_FOUND);
        }

        $cart->removeProduct($product);
        $cart->setQuantityForProduct($product, 0);  // Set quantity to zero or manage as needed
        $entityManager->flush();

        return new JsonResponse(['status' => 'Product removed from cart'], Response::HTTP_OK);
    }

    #[Route('/api/carts', methods: ['GET'])]
    public function getCartProducts(EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $user = $security->getUser();
        if (!$user) {
            throw new AccessDeniedException('This user does not have access to this resource.');
        }

        $cart = $entityManager->getRepository(Cart::class)->findOneBy(['user' => $user]);
        if (!$cart) {
            return new JsonResponse(['message' => 'No cart found'], Response::HTTP_NOT_FOUND);
        }

        $products = $cart->getProducts();
        $data = $products->map(function (Product $product) use ($cart) {
            return [
                'product_id' => $product->getId(),
                'name' => $product->getName(),
                'quantity' => $cart->getQuantityForProduct($product),
                'price' => $product->getPrice(),
            ];
        })->toArray();

        return new JsonResponse($data, Response::HTTP_OK);
    }

    #[Route('/api/carts/validate', name: 'cart_validate', methods: ['POST'])]
    public function validateCart(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        $cart = $user->getCart();

        if (!$cart) {
            return $this->json(['error' => 'No cart found'], 404);
        }

        $order = new Order();
        $order->setUser($user);
        $order->setCreationDate(new \DateTime());

        $totalPrice = 0;
        foreach ($cart->getProducts() as $product) {
            $order->addProduct($product);
            $totalPrice += $product->getPrice();
        }
        $order->setTotalPrice($totalPrice);

        $entityManager->persist($order);
        $entityManager->flush();

        // Optionally clear the cart
        $cart->getProducts()->clear();
        $entityManager->persist($cart);
        $entityManager->flush();

        return $this->json([
            'message' => 'Cart validated and order created successfully',
            'order_id' => $order->getId(),
            'total_price' => $totalPrice,
            'products' => $order->getProducts()->toArray()
        ]);
    }

}
